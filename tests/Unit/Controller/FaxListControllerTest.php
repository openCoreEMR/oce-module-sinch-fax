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
        // Mock reconciliation call
        $this->faxService->expects($this->once())
            ->method('reconcileInboundFaxes')
            ->willReturn([]);

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE read_status != 'archived' ORDER BY created_at DESC LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('default');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDispatchListAction(): void
    {
        $this->faxService->expects($this->once())
            ->method('reconcileInboundFaxes')
            ->willReturn([]);

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE read_status != 'archived' ORDER BY created_at DESC LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowFaxListWithDirectionFilter(): void
    {
        $_GET['direction'] = 'INBOUND';

        $this->faxService->expects($this->once())
            ->method('reconcileInboundFaxes')
            ->willReturn([]);

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE direction = ? AND read_status != 'archived' ORDER BY created_at DESC LIMIT 50",
            ['INBOUND'],
            [
                [
                    'id' => 1,
                    'sinch_fax_id' => 'fax-123',
                    'direction' => 'INBOUND',
                    'status' => 'COMPLETED',
                    'read_status' => 'unread',
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

    public function testShowFaxListWithShowArchived(): void
    {
        $_GET['show_archived'] = '1';

        $this->faxService->expects($this->once())
            ->method('reconcileInboundFaxes')
            ->willReturn([]);

        // When show_archived=1, no read_status filter is applied
        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50",
            [],
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

        $this->faxService->expects($this->once())
            ->method('reconcileInboundFaxes')
            ->willReturn([]);

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE read_status != 'archived' ORDER BY created_at DESC LIMIT 50",
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

    public function testShowFaxListWithReconciliationError(): void
    {
        // Reconciliation throws but list still works
        $this->faxService->expects($this->once())
            ->method('reconcileInboundFaxes')
            ->willThrowException(new \Exception('API error'));

        QueryUtils::setMockResult(
            "SELECT * FROM oce_sinch_faxes WHERE read_status != 'archived' ORDER BY created_at DESC LIMIT 50",
            [],
            []
        );

        $response = $this->controller->dispatch('list');

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
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
