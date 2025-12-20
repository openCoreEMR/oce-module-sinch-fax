<?php

/**
 * Unit tests for Bootstrap
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit;

use OpenCoreEMR\Modules\SinchFax\Bootstrap;
use OpenCoreEMR\Modules\SinchFax\Controller\DocumentFaxController;
use OpenCoreEMR\Modules\SinchFax\Controller\FaxDownloadController;
use OpenCoreEMR\Modules\SinchFax\Controller\FaxListController;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

class BootstrapTest extends TestCase
{
    private Bootstrap $bootstrap;
    private EventDispatcher $eventDispatcher;

    protected function setUp(): void
    {
        // Clear logs before each test
        SystemLogger::clearLogs();

        $this->eventDispatcher = new EventDispatcher();

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '1',
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'use1',
        ]);

        $this->bootstrap = new Bootstrap($this->eventDispatcher, globals: $mockGlobals);
    }

    protected function tearDown(): void
    {
        SystemLogger::clearLogs();
    }

    public function testBootstrapCanBeConstructed(): void
    {
        $this->assertInstanceOf(Bootstrap::class, $this->bootstrap);
    }

    public function testBootstrapLogsDebugMessageOnConstruction(): void
    {
        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter($logs, fn($log) => $log['level'] === 'debug');

        $this->assertNotEmpty($debugLogs);
        $constructLog = array_filter($debugLogs, fn($log) =>
            str_contains($log['message'], 'Sinch Fax Bootstrap constructed')
        );
        $this->assertNotEmpty($constructLog);
    }

    public function testGetFaxListControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getFaxListController();

        $this->assertInstanceOf(FaxListController::class, $controller);
    }

    public function testGetDocumentFaxControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getDocumentFaxController();

        $this->assertInstanceOf(DocumentFaxController::class, $controller);
    }

    public function testGetFaxDownloadControllerReturnsController(): void
    {
        $controller = $this->bootstrap->getFaxDownloadController();

        $this->assertInstanceOf(FaxDownloadController::class, $controller);
    }

    public function testSubscribeToEventsCallsAddGlobalSettings(): void
    {
        $this->bootstrap->subscribeToEvents();

        // Verify that event listeners were added
        $listeners = $this->eventDispatcher->getListeners();
        $this->assertNotEmpty($listeners);
    }

    public function testSubscribeToEventsExitsEarlyWhenNotConfigured(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '1',
            // Missing required config
        ]);

        $bootstrap = new Bootstrap($this->eventDispatcher, globals: $mockGlobals);

        SystemLogger::clearLogs();
        $bootstrap->subscribeToEvents();

        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter($logs, fn($log) =>
            str_contains($log['message'], 'not configured')
        );

        $this->assertNotEmpty($debugLogs);
    }

    public function testSubscribeToEventsExitsEarlyWhenDisabled(): void
    {
        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_ENABLED => '0',
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_REGION => 'use1',
        ]);

        $bootstrap = new Bootstrap($this->eventDispatcher, globals: $mockGlobals);

        SystemLogger::clearLogs();
        $bootstrap->subscribeToEvents();

        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter($logs, fn($log) =>
            str_contains($log['message'], 'disabled')
        );

        $this->assertNotEmpty($debugLogs);
    }

    public function testSubscribeToEventsLogsSuccessWhenEnabledAndConfigured(): void
    {
        SystemLogger::clearLogs();
        $this->bootstrap->subscribeToEvents();

        $logs = SystemLogger::getLogs();
        $debugLogs = array_filter($logs, fn($log) =>
            str_contains($log['message'], 'enabled and configured')
        );

        $this->assertNotEmpty($debugLogs);
    }

    public function testAddGlobalSettingsAddsEventListener(): void
    {
        $listenersBefore = count($this->eventDispatcher->getListeners());

        $this->bootstrap->addGlobalSettings();

        $listenersAfter = count($this->eventDispatcher->getListeners());

        $this->assertGreaterThan($listenersBefore, $listenersAfter);
    }

    public function testAddMenuItemsAddsEventListener(): void
    {
        $listenersBefore = count($this->eventDispatcher->getListeners());

        $this->bootstrap->addMenuItems();

        $listenersAfter = count($this->eventDispatcher->getListeners());

        $this->assertGreaterThan($listenersBefore, $listenersAfter);
    }

    public function testAddDocumentViewerIntegrationAddsEventListeners(): void
    {
        $listenersBefore = count($this->eventDispatcher->getListeners());

        $this->bootstrap->addDocumentViewerIntegration();

        $listenersAfter = count($this->eventDispatcher->getListeners());

        // Should add 2 listeners (fax anchor and javascript)
        $this->assertGreaterThanOrEqual($listenersBefore + 2, $listenersAfter);
    }

    public function testRenderDocumentFaxButtonOutputsHTML(): void
    {
        ob_start();
        $this->bootstrap->renderDocumentFaxButton();
        $output = ob_get_clean();

        $this->assertStringContainsString('btn-send-msg', $output);
        $this->assertStringContainsString('doSinchFax', $output);
    }

    public function testRenderDocumentFaxJavaScriptOutputsJavaScript(): void
    {
        ob_start();
        $this->bootstrap->renderDocumentFaxJavaScript();
        $output = ob_get_clean();

        $this->assertStringContainsString('function doSinchFax', $output);
        $this->assertStringContainsString('dlgopen', $output);
        $this->assertStringContainsString(Bootstrap::MODULE_NAME, $output);
    }

    public function testModuleNameConstant(): void
    {
        $this->assertEquals('oce-module-sinch-fax', Bootstrap::MODULE_NAME);
    }
}
