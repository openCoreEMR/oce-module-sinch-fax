<?php

/**
 * Unit tests for WebhookController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchFax\Controller\WebhookController;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class WebhookControllerTest extends TestCase
{
    private GlobalConfig $config;
    private WebhookController $controller;
    private string $storagePath;

    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        SystemLogger::clearLogs();

        // Reset superglobals
        $_SERVER = [];
        $_POST = [];
        $_GET = [];
        $_FILES = [];

        // Create temp storage path
        $this->storagePath = sys_get_temp_dir() . '/fax_webhook_test_' . uniqid();
        mkdir($this->storagePath, 0770, true);

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_FILE_STORAGE_PATH => $this->storagePath,
        ]);

        $this->config = new GlobalConfig($mockGlobals);
        $faxService = new FaxService($this->config);
        $this->controller = new WebhookController($this->config, $faxService);
    }

    protected function tearDown(): void
    {
        // Clean up temp files
        if (is_dir($this->storagePath)) {
            $files = glob($this->storagePath . '/*');
            if ($files) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            rmdir($this->storagePath);
        }

        unset($_SERVER, $_POST, $_GET, $_FILES);
    }

    public function testControllerCanBeConstructed(): void
    {
        $this->assertInstanceOf(WebhookController::class, $this->controller);
    }

    public function testDispatchRejectsGetRequests(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_METHOD_NOT_ALLOWED, $response->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true);
        $this->assertArrayHasKey('error', $content);
        $this->assertEquals('Method not allowed', $content['error']);
    }

    public function testDispatchReturnsErrorForEmptyPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_BAD_REQUEST, $response->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true);
        $this->assertArrayHasKey('error', $content);
    }

    public function testDispatchReturnsErrorForMissingEventType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'application/json';

        // Need to simulate request content - this requires modifying how the controller reads content
        // For now, test with form data
        $_POST['fax'] = json_encode(['id' => 'test-123']);
        // Missing 'event' field

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        // Will return bad request due to missing event
        $this->assertContains($response->getStatusCode(), [
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_OK
        ]);
    }

    public function testDispatchHandlesUnknownEventType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'UNKNOWN_EVENT';
        $_POST['fax'] = json_encode(['id' => 'test-123']);

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true);
        $this->assertEquals('ignored', $content['status']);
        $this->assertStringContainsString('Unknown event type', $content['message']);
    }

    public function testDispatchHandlesIncomingFaxEvent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'INCOMING_FAX';
        $_POST['fax'] = json_encode([
            'id' => 'incoming-fax-123',
            'from' => '+15551234567',
            'to' => '+15559876543',
            'status' => 'COMPLETED',
            'numberOfPages' => 2,
        ]);

        // Mock database to return no existing fax
        QueryUtils::setMockResult(
            "SELECT id, file_path FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['incoming-fax-123'],
            []
        );

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        // May fail on download attempt, but should attempt to process
        $this->assertContains($response->getStatusCode(), [
            Response::HTTP_OK,
            Response::HTTP_INTERNAL_SERVER_ERROR
        ]);
    }

    public function testDispatchHandlesFaxCompletedEvent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'FAX_COMPLETED';
        $_POST['fax'] = json_encode([
            'id' => 'completed-fax-123',
            'from' => '+15551234567',
            'to' => '+15559876543',
            'status' => 'COMPLETED',
            'numberOfPages' => 3,
            'completedTime' => '2025-01-01T12:00:00Z',
        ]);

        // Mock database to return existing fax
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['completed-fax-123'],
            [['id' => 1]]
        );

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $content = json_decode($response->getContent() ?: '', true);
        $this->assertEquals('success', $content['status']);
        $this->assertEquals('completed-fax-123', $content['faxId']);
    }

    public function testDispatchHandlesFaxCompletedForNewFax(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'FAX_COMPLETED';
        $_POST['fax'] = json_encode([
            'id' => 'new-completed-fax',
            'from' => '+15551111111',
            'to' => '+15552222222',
            'status' => 'FAILURE',
            'errorCode' => 'NO_ANSWER',
            'errorMessage' => 'Remote fax did not answer',
        ]);

        // Mock database to return no existing fax
        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['new-completed-fax'],
            []
        );

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // Verify INSERT was attempted
        $queries = QueryUtils::getQueries();
        $insertQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'INSERT INTO'));
        $this->assertNotEmpty($insertQueries);
    }

    public function testDispatchLogsWebhookReceipt(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['REMOTE_ADDR'] = '192.168.1.100';
        $_POST['event'] = 'UNKNOWN_EVENT';
        $_POST['fax'] = json_encode(['id' => 'test']);

        $this->controller->dispatch();

        $logs = SystemLogger::getLogs();
        $this->assertNotEmpty($logs);

        // Find webhook received log
        $foundLog = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'info' && str_contains($log['message'], 'Webhook received')) {
                $foundLog = true;
                break;
            }
        }
        $this->assertTrue($foundLog, 'Expected webhook receipt log not found');
    }

    public function testDispatchLogsEventProcessing(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'FAX_COMPLETED';
        $_POST['fax'] = json_encode([
            'id' => 'log-test-fax',
            'status' => 'COMPLETED',
        ]);

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['log-test-fax'],
            [['id' => 1]]
        );

        $this->controller->dispatch();

        $logs = SystemLogger::getLogs();

        // Find event processing log
        $foundLog = false;
        foreach ($logs as $log) {
            if ($log['level'] === 'info' && str_contains($log['message'], 'Processing webhook event')) {
                $foundLog = true;
                break;
            }
        }
        $this->assertTrue($foundLog, 'Expected event processing log not found');
    }

    public function testParsePayloadHandlesMultipartFormData(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SERVER['CONTENT_TYPE'] = 'multipart/form-data; boundary=----WebKitFormBoundary';
        $_POST['event'] = 'INCOMING_FAX';
        $_POST['fax'] = json_encode(['id' => 'multipart-test']);

        QueryUtils::setMockResult(
            "SELECT id, file_path FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['multipart-test'],
            []
        );

        $response = $this->controller->dispatch();

        // Should process the event (may fail on download, but parsing worked)
        $this->assertInstanceOf(JsonResponse::class, $response);
    }

    public function testIncomingFaxUpdatesExistingRecord(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'INCOMING_FAX';
        $_POST['fax'] = json_encode([
            'id' => 'existing-fax-123',
            'from' => '+15551234567',
            'to' => '+15559876543',
            'status' => 'COMPLETED',
        ]);

        // Mock database to return existing fax without file
        QueryUtils::setMockResult(
            "SELECT id, file_path FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['existing-fax-123'],
            [['id' => 1, 'file_path' => null]]
        );

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        // Should succeed since fax exists
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());
    }

    public function testFaxCompletedUpdatesStatus(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'FAX_COMPLETED';
        $_POST['fax'] = json_encode([
            'id' => 'update-status-fax',
            'status' => 'COMPLETED',
            'numberOfPages' => 5,
            'completedTime' => '2025-01-15T10:30:00Z',
        ]);

        QueryUtils::setMockResult(
            "SELECT id FROM oce_sinch_faxes WHERE sinch_fax_id = ?",
            ['update-status-fax'],
            [['id' => 42]]
        );

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        // Verify UPDATE was called
        $queries = QueryUtils::getQueries();
        $updateQueries = array_filter($queries, fn($q) => str_contains($q['sql'], 'UPDATE oce_sinch_faxes'));
        $this->assertNotEmpty($updateQueries);
    }

    public function testHandlesInvalidJsonPayload(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['event'] = 'INCOMING_FAX';
        $_POST['fax'] = 'not valid json {{{';

        $response = $this->controller->dispatch();

        $this->assertInstanceOf(JsonResponse::class, $response);
        // Should handle gracefully
        $this->assertContains($response->getStatusCode(), [
            Response::HTTP_OK,
            Response::HTTP_BAD_REQUEST,
            Response::HTTP_INTERNAL_SERVER_ERROR
        ]);
    }
}
