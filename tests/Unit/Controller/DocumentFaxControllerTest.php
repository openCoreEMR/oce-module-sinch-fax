<?php

/**
 * Unit tests for DocumentFaxController
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Tests\Unit\Controller;

use OpenCoreEMR\Modules\SinchFax\Controller\DocumentFaxController;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class DocumentFaxControllerTest extends TestCase
{
    private GlobalConfig $config;
    private FaxService $faxService;
    private Environment $twig;
    private DocumentFaxController $controller;

    protected function setUp(): void
    {
        // Initialize globals
        $_POST = [];
        $_GET = [];
        $_SERVER = [];

        $mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',
            GlobalConfig::CONFIG_OPTION_AUTH_METHOD => 'basic',
            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
        ]);

        $this->config = new GlobalConfig($mockGlobals);
        $this->faxService = $this->createMock(FaxService::class);

        // Create a simple Twig environment for testing
        $loader = new ArrayLoader([
            'fax/send-document.html.twig' => 'Send Document Template',
        ]);
        $this->twig = new Environment($loader);

        $this->controller = new DocumentFaxController(
            $this->config,
            $this->faxService,
            $this->twig
        );
    }

    public function testControllerCanBeConstructed(): void
    {
        $this->assertInstanceOf(DocumentFaxController::class, $this->controller);
    }
}
