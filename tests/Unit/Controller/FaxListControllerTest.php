<?php

/**
 * Unit tests for FaxListController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchFax\Controller\FaxListController;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FaxListControllerTest extends TestCase
{
    private GlobalConfig $config;
    private FaxService&MockObject $faxService;
    private Environment $twig;
    private FaxListController $controller;

    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        CsrfUtils::reset();
        SystemLogger::clearLogs();

        // Initialize globals
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_FILES = [];

        // Start session if not already started
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        // Clear session
        $_SESSION = [];

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => false,
            'assets_static_relative' => '/assets',
        ]);

        $this->config = new GlobalConfig($mockGlobals);
        $this->faxService = $this->createMock(FaxService::class);

        // Create a simple Twig environment for testing
        $loader = new ArrayLoader([
            'fax/list.html.twig' => '<html>{{ faxes|length }} faxes|' .
                '{{ success_message }}|{{ error_message }}</html>',
        ]);
        $this->twig = new Environment($loader);

        $this->controller = new FaxListController(
            $this->config,
            $this->faxService,
            $this->twig
        );
    }

    protected function tearDown(): void
    {
        // Clean up session and globals
        $_SESSION = [];
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_FILES = [];
    }

    public function testDispatchDefaultAction(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('default');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDispatchListAction(): void
    {
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListWithFilters(): void
    {
        $_GET['direction'] = 'INBOUND';
        $_GET['status'] = 'COMPLETED';

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE direction = ? AND status = ? ORDER BY created_at DESC LIMIT 50",
            ['INBOUND', 'COMPLETED'],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-123',
                    'direction' => 'INBOUND',
                    'status' => 'COMPLETED',
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'num_pages' => 3,
                    'created_at' => '2025-01-01 12:00:00',
                ],
            ]
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('1 faxes', $content);
    }

    public function testShowFaxListWithDirectionFilterOnly(): void
    {
        $_GET['direction'] = 'OUTBOUND';

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE direction = ? ORDER BY created_at DESC LIMIT 50",
            ['OUTBOUND'],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListWithStatusFilterOnly(): void
    {
        $_GET['status'] = 'FAILURE';

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE status = ? ORDER BY created_at DESC LIMIT 50",
            ['FAILURE'],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListDisplaysFlashMessages(): void
    {
        $_SESSION['fax_success'] = 'Fax sent successfully!';
        $_SESSION['fax_error'] = 'Something went wrong';

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Fax sent successfully!', $content);
        $this->assertStringContainsString('Something went wrong', $content);

        // Flash messages should be cleared after display
        $this->assertArrayNotHasKey('fax_success', $_SESSION);
        $this->assertArrayNotHasKey('fax_error', $_SESSION);
    }

    public function testShowFaxListWithDatabaseError(): void
    {
        // Don't set a mock result - this will cause fetchRecords to return empty array
        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListWithStatusPollingEnabled(): void
    {
        // Create controller with status polling enabled
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => true,
            'assets_static_relative' => '/assets',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $controller = new FaxListController(
            $config,
            $this->faxService,
            $this->twig
        );

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-123',
                    'direction' => 'OUTBOUND',
                    'status' => 'IN_PROGRESS',
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'num_pages' => 0,
                    'error_message' => null,
                    'created_at' => '2025-01-01 12:00:00',
                ],
            ]
        );

        // Mock the fax service to return updated status
        $this->faxService->expects($this->once())
            ->method('getFax')
            ->with('fax-123')
            ->willReturn([
                'status' => 'COMPLETED',
                'numberOfPages' => 5,
            ]);

        $response = $controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());

        // Verify the update query was executed
        $queries = QueryUtils::getQueries();
        $updateFound = false;
        foreach ($queries as $query) {
            if (str_contains($query['sql'], 'UPDATE oce_sinch_faxes SET status')) {
                $updateFound = true;
                break;
            }
        }
        $this->assertTrue($updateFound, 'Update query should have been executed');
    }

    public function testShowFaxListWithStatusPollingFetchError(): void
    {
        // Create controller with status polling enabled
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => true,
            'assets_static_relative' => '/assets',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $controller = new FaxListController(
            $config,
            $this->faxService,
            $this->twig
        );

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-123',
                    'direction' => 'OUTBOUND',
                    'status' => 'IN_PROGRESS',
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'num_pages' => 0,
                    'error_message' => null,
                    'created_at' => '2025-01-01 12:00:00',
                ],
            ]
        );

        // Mock the fax service to throw an exception
        $this->faxService->expects($this->once())
            ->method('getFax')
            ->with('fax-123')
            ->willThrowException(new \Exception('API error'));

        $response = $controller->dispatch('list');

        // Should still return successfully despite the error
        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListWithStatusPollingFailureNeedsErrorMessage(): void
    {
        // Create controller with status polling enabled
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => true,
            'assets_static_relative' => '/assets',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $controller = new FaxListController(
            $config,
            $this->faxService,
            $this->twig
        );

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-456',
                    'direction' => 'OUTBOUND',
                    'status' => 'FAILURE',
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'num_pages' => 0,
                    'error_message' => '', // Empty error message - should poll
                    'created_at' => '2025-01-01 12:00:00',
                ],
            ]
        );

        // Mock the fax service to return error details
        $this->faxService->expects($this->once())
            ->method('getFax')
            ->with('fax-456')
            ->willReturn([
                'status' => 'FAILURE',
                'numberOfPages' => 0,
                'errorCode' => 'BUSY',
                'errorMessage' => 'Line busy',
            ]);

        $response = $controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListNoPollingWhenNoChanges(): void
    {
        // Create controller with status polling enabled
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => true,
            'assets_static_relative' => '/assets',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $controller = new FaxListController(
            $config,
            $this->faxService,
            $this->twig
        );

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-789',
                    'direction' => 'OUTBOUND',
                    'status' => 'IN_PROGRESS',
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'num_pages' => 0,
                    'error_message' => null,
                    'created_at' => '2025-01-01 12:00:00',
                ],
            ]
        );

        // Mock the fax service to return same status (no changes)
        $this->faxService->expects($this->once())
            ->method('getFax')
            ->with('fax-789')
            ->willReturn([
                'status' => 'IN_PROGRESS',
                'numberOfPages' => 0,
            ]);

        $response = $controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);

        // Verify NO update query was executed since nothing changed
        $queries = QueryUtils::getQueries();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                'UPDATE oce_sinch_faxes SET status',
                $query['sql'],
                'No update should occur when status unchanged'
            );
        }
    }

    public function testShowFaxListSkipsPollingForCompletedFaxes(): void
    {
        // Create controller with status polling enabled
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLE_STATUS_POLLING => true,
            'assets_static_relative' => '/assets',
        ]);

        $config = new GlobalConfig($mockGlobals);
        $controller = new FaxListController(
            $config,
            $this->faxService,
            $this->twig
        );

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-completed',
                    'direction' => 'OUTBOUND',
                    'status' => 'COMPLETED', // Already completed
                    'from_number' => '+1234567890',
                    'to_number' => '+0987654321',
                    'num_pages' => 5,
                    'error_message' => null,
                    'created_at' => '2025-01-01 12:00:00',
                ],
            ]
        );

        // getFax should NOT be called for completed faxes
        $this->faxService->expects($this->never())
            ->method('getFax');

        $response = $controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testHandleSendFaxWithoutPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testHandleSendFaxMissingRecipient(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['to'] = '';

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('Recipient number is required', $_SESSION['fax_error']);
    }

    public function testHandleSendFaxMissingFiles(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['to'] = '+15551234567';
        $_FILES = [];

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('At least one file is required', $_SESSION['fax_error']);
    }

    public function testHandleSendFaxEmptyFileArray(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['to'] = '+15551234567';

        // Empty files array
        $_FILES['files'] = [
            'name' => [''],
            'type' => [''],
            'tmp_name' => [''],
            'error' => [UPLOAD_ERR_NO_FILE],
            'size' => [0],
        ];

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('At least one file is required', $_SESSION['fax_error']);
    }

    public function testHandleMoveToPatientWithoutPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);
    }

    public function testHandleMoveToPatientMissingParameters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['fax_id'] = '';
        $_POST['patient_id'] = '';

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('Missing required parameters', $_SESSION['fax_error']);
    }

    public function testHandleMoveToPatientMissingFaxId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['fax_id'] = '0';
        $_POST['patient_id'] = '100';

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('Missing required parameters', $_SESSION['fax_error']);
    }

    public function testHandleMoveToPatientMissingPatientId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['fax_id'] = '1';
        $_POST['patient_id'] = '0';

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('Missing required parameters', $_SESSION['fax_error']);
    }

    public function testHandleMoveToPatientSuccess(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['fax_id'] = '1';
        $_POST['patient_id'] = '100';

        $this->faxService->expects($this->once())
            ->method('moveToPatientDocuments')
            ->with(1, 100)
            ->willReturn(456);

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_success', $_SESSION);
        $this->assertStringContainsString('Document ID: 456', $_SESSION['fax_success']);
    }

    public function testHandleMoveToPatientFailure(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token'] = 'test-csrf-token';
        $_POST['fax_id'] = '1';
        $_POST['patient_id'] = '100';

        $this->faxService->expects($this->once())
            ->method('moveToPatientDocuments')
            ->with(1, 100)
            ->willThrowException(new \Exception('Test error'));

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);
        $this->assertArrayHasKey('fax_error', $_SESSION);
        $this->assertEquals('Error moving fax: Test error', $_SESSION['fax_error']);
    }

    public function testRedirectPreservesQueryParameters(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_GET['direction'] = 'INBOUND';
        $_GET['action'] = 'send'; // This should be removed

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('direction=INBOUND', $location);
        $this->assertStringNotContainsString('action=', $location);
    }

    public function testRedirectUsesScriptName(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['SCRIPT_NAME'] = '/interface/modules/custom_modules/oce-module-sinch-fax/public/index.php';

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);

        $location = $response->headers->get('Location');
        $this->assertIsString($location);
        $this->assertStringContainsString('/interface/modules/custom_modules/', $location);
    }
}
