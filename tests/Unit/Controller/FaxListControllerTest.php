<?php

/**
 * Unit tests for FaxListController
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class FaxListControllerTest extends TestCase
{
    private GlobalConfig $config;
    private FaxService $faxService;
    private Environment $twig;
    private FaxListController $controller;

    protected function setUp(): void
    {
        // Clear mock data
        QueryUtils::clearQueries();
        QueryUtils::clearMockResults();
        CsrfUtils::reset();

        // Initialize globals
        $_POST = [];
        $_GET = [];
        $_SERVER = [];

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
            'fax/list.html.twig' => 'Fax List Template',
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
        // Clean up session
        $_SESSION = [];
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

        // Clean up $_GET
        unset($_GET['direction'], $_GET['status']);
    }

    public function testHandleSendFaxWithoutPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('send');

        $this->assertInstanceOf(RedirectResponse::class, $response);

        unset($_SERVER['REQUEST_METHOD']);
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

        unset($_SERVER['REQUEST_METHOD'], $_POST);
    }

    public function testHandleMoveToPatientWithoutPost(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('move_to_patient');

        $this->assertInstanceOf(RedirectResponse::class, $response);

        unset($_SERVER['REQUEST_METHOD']);
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

        unset($_SERVER['REQUEST_METHOD'], $_POST);
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

        unset($_SERVER['REQUEST_METHOD'], $_POST);
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

        unset($_SERVER['REQUEST_METHOD'], $_POST);
    }
}
