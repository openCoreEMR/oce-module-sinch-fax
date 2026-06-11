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
use OpenCoreEMR\Modules\SinchFax\Exception\FaxAccessDeniedException;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockDocument;
use OpenCoreEMR\Modules\SinchFax\Tests\Mocks\MockGlobalsAccessor;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

class DocumentFaxControllerTest extends TestCase
{
    private GlobalConfig $config;
    private FaxService&MockObject $faxService;
    private Environment $twig;
    private SessionInterface&MockObject $session;
    private DocumentFaxController $controller;
    private MockGlobalsAccessor $mockGlobals;

    protected function setUp(): void
    {
        // Clear mock data
        MockDocument::clearMockDocuments();
        CsrfUtils::reset();
        SystemLogger::clearLogs();

        // Initialize superglobals
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_SESSION = [];

        $this->mockGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',

            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLED => true,
        ]);

        $this->config = new GlobalConfig($this->mockGlobals);
        $this->faxService = $this->createMock(FaxService::class);
        $this->session = $this->createMock(SessionInterface::class);

        // Create a simple Twig environment for testing
        $loader = new ArrayLoader([
            'fax/send-document.html.twig' => '<html>{{ document_name }}|{{ patient_id }}|' .
                '{{ is_documents }}|{{ doc_id }}|{{ error_message }}|{{ success_message }}</html>',
        ]);
        $this->twig = new Environment($loader);

        $this->controller = new DocumentFaxController(
            $this->config,
            $this->faxService,
            $this->twig,
            $this->session
        );
    }

    protected function tearDown(): void
    {
        // Clean up superglobals
        $_POST = [];
        $_GET = [];
        $_SERVER = [];
        $_SESSION = [];
        MockDocument::clearMockDocuments();
    }

    public function testControllerCanBeConstructed(): void
    {
        $this->assertInstanceOf(DocumentFaxController::class, $this->controller);
    }

    public function testDispatchDefaultActionShowsForm(): void
    {
        $response = $this->controller->dispatch('default', []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDispatchShowActionShowsForm(): void
    {
        $response = $this->controller->dispatch('show', []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testDispatchSendActionWithGetShowsForm(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testShowSendFormThrowsWhenModuleDisabled(): void
    {
        // Create disabled config
        $disabledGlobals = new MockGlobalsAccessor([
            GlobalConfig::CONFIG_OPTION_PROJECT_ID => 'test-project-id',

            GlobalConfig::CONFIG_OPTION_API_KEY => 'test-key',
            GlobalConfig::CONFIG_OPTION_API_SECRET => base64_encode('test-secret'),
            GlobalConfig::CONFIG_OPTION_ENABLED => false,
        ]);

        $disabledConfig = new GlobalConfig($disabledGlobals);
        $controller = new DocumentFaxController(
            $disabledConfig,
            $this->faxService,
            $this->twig,
            $this->session
        );

        $this->expectException(FaxAccessDeniedException::class);
        $this->expectExceptionMessage('Sinch Fax module is not enabled');

        $controller->dispatch('show', []);
    }

    public function testShowSendFormWithDocumentLoadsDocumentName(): void
    {
        MockDocument::setMockDocument(123, [
            'name' => 'test-document.pdf',
            'foreign_id' => 456,
        ]);

        $response = $this->controller->dispatch('show', [
            'isDocuments' => 1,
            'docid' => 123,
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('test-document.pdf', $content);
    }

    public function testShowSendFormWithDocumentUsesDocumentForeignIdWhenPidEmpty(): void
    {
        MockDocument::setMockDocument(123, [
            'name' => 'test-document.pdf',
            'foreign_id' => 789,
        ]);

        $response = $this->controller->dispatch('show', [
            'isDocuments' => 1,
            'docid' => 123,
            'pid' => '', // Empty pid should use document's foreign_id
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        // Patient ID should be 789 from foreign_id
        $this->assertStringContainsString('789', $content);
    }

    public function testShowSendFormWithoutDocument(): void
    {
        $response = $this->controller->dispatch('show', [
            'isDocuments' => 0,
            'file' => '/path/to/file.pdf',
            'mime' => 'application/pdf',
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $this->assertEquals(200, $response->getStatusCode());
    }

    public function testHandleSendFaxThrowsOnCsrfFailure(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'invalid-token';
        CsrfUtils::setVerifyResult(false);

        $this->expectException(FaxAccessDeniedException::class);
        $this->expectExceptionMessage('CSRF token verification failed');

        $this->controller->dispatch('send', []);
    }

    public function testHandleSendFaxWithEmptyRecipient(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '';
        CsrfUtils::setVerifyResult(true);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Recipient fax number is required', $content);
    }

    public function testHandleSendFaxWithoutDocumentIdShowsError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 0;
        $_POST['doc_id'] = '';
        CsrfUtils::setVerifyResult(true);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('No document specified', $content);
    }

    public function testHandleSendFaxWithEmptyDocumentContent(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'empty-doc.pdf',
            'data' => '', // Empty content
        ]);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Document has no content', $content);
    }

    public function testHandleSendFaxWithDocumentRetrievalError(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'error-doc.pdf',
            'throw_exception' => true,
            'exception_message' => 'Cannot read document',
        ]);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Error retrieving document', $content);
        $this->assertStringContainsString('Cannot read document', $content);
    }

    public function testHandleSendFaxSuccessWithPdfDocument(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        $_POST['patient_id'] = '456';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.pdf',
            'mimetype' => 'application/pdf',
            'data' => '%PDF-1.4 test content',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Fax sent successfully', $content);
    }

    public function testHandleSendFaxSuccessWithTiffDocument(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.tiff',
            'mimetype' => 'image/tiff',
            'data' => 'TIFF image data',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Fax sent successfully', $content);
    }

    public function testHandleSendFaxSuccessWithPngDocument(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.png',
            'mimetype' => 'image/png',
            'data' => 'PNG image data',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Fax sent successfully', $content);
    }

    public function testHandleSendFaxSuccessWithJpegDocument(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.jpg',
            'mimetype' => 'image/jpeg',
            'data' => 'JPEG image data',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        $this->assertStringContainsString('Fax sent successfully', $content);
    }

    public function testHandleSendFaxWithoutPatientId(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        $_POST['patient_id'] = ''; // Empty patient ID
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.pdf',
            'mimetype' => 'application/pdf',
            'data' => '%PDF-1.4 test content',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->with(
                '+15551234567',
                $this->anything(),
                $this->callback(function ($options) {
                    // patient_id should NOT be in options when empty
                    return isset($options['document_id']) && !isset($options['patient_id']);
                })
            )
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testHandleSendFaxServiceThrowsException(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.pdf',
            'mimetype' => 'application/pdf',
            'data' => '%PDF-1.4 test content',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willThrowException(new \Exception('API error'));

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        // The sendFax exception is caught inside the inner try-catch, so message is "Error retrieving document"
        $this->assertStringContainsString('Error retrieving document', $content);
        $this->assertStringContainsString('API error', $content);
    }

    public function testHandleSendFaxWithTifMimeType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.tif',
            'mimetype' => 'image/tif',
            'data' => 'TIFF image data',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testHandleSendFaxWithJpgMimeType(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST['csrf_token_form'] = 'valid-token';
        $_POST['recipient'] = '+15551234567';
        $_POST['is_documents'] = 1;
        $_POST['doc_id'] = '123';
        CsrfUtils::setVerifyResult(true);

        MockDocument::setMockDocument(123, [
            'name' => 'test-document.jpg',
            'mimetype' => 'image/jpg',
            'data' => 'JPEG image data',
        ]);

        $this->faxService->expects($this->once())
            ->method('sendFax')
            ->willReturn(['id' => 'fax-123']);

        $response = $this->controller->dispatch('send', []);

        $this->assertInstanceOf(Response::class, $response);
    }

    public function testShowSendFormWithProvidedPid(): void
    {
        MockDocument::setMockDocument(123, [
            'name' => 'test-document.pdf',
            'foreign_id' => 789,
        ]);

        $response = $this->controller->dispatch('show', [
            'isDocuments' => 1,
            'docid' => 123,
            'pid' => 456, // Provided pid should be used, not foreign_id
        ]);

        $this->assertInstanceOf(Response::class, $response);
        $content = $response->getContent();
        $this->assertIsString($content);
        // Patient ID should be 456 (provided), not 789 (from foreign_id)
        $this->assertStringContainsString('456', $content);
    }
}
