<?php

/**
 * Document Fax Controller - handles faxing from document viewer
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\Exception\FaxAccessDeniedException;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class DocumentFaxController
{
    public function __construct(
        private readonly GlobalConfig $config,
        private readonly FaxService $faxService,
        private readonly Environment $twig
    ) {
    }

    /**
     * Dispatch action to appropriate method
     *
     * @param array<string, mixed> $params
     */
    public function dispatch(string $action, array $params): Response
    {
        $request = Request::createFromGlobals();

        return match ($action) {
            'send' => $this->handleSendFax($request, $params),
            'show' => $this->showSendForm($params),
            default => $this->showSendForm($params),
        };
    }

    /**
     * Show send fax form for a document
     *
     * @param array<string, mixed> $params
     */
    private function showSendForm(array $params): Response
    {
        // Check if module is enabled
        if (!$this->config->isEnabled()) {
            throw new FaxAccessDeniedException("Sinch Fax module is not enabled");
        }

        $isDocumentsRaw = $params['isDocuments'] ?? 0;
        $isDocuments = is_numeric($isDocumentsRaw) ? (int)$isDocumentsRaw : 0;
        $docId = $params['docid'] ?? '';
        $pid = $params['pid'] ?? '';

        // Load document if available
        $document = null;
        $documentName = '';
        if ($isDocuments && !empty($docId)) {
            $document = new \Document($docId);
            $documentName = $document->get_name();
            if (empty($pid)) {
                $pid = $document->get_foreign_id();
            }
        }

        // Render template
        $content = $this->twig->render('fax/send-document.html.twig', [
            'document_name' => $documentName,
            'patient_id' => $pid,
            'is_documents' => $isDocuments,
            'doc_id' => $docId,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
            'error_message' => null,
            'success_message' => null,
        ]);

        return new Response($content);
    }

    /**
     * Handle fax sending from document
     *
     * @param array<string, mixed> $params
     */
    private function handleSendFax(Request $request, array $params): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->showSendForm($params);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token_form', ''))) {
            throw new FaxAccessDeniedException("CSRF token verification failed");
        }

        $recipient = (string)$request->request->get('recipient', '');
        $isDocuments = (int)$request->request->get('is_documents', 0);
        $docId = $request->request->get('doc_id', '');
        $pid = $request->request->get('patient_id', '');

        $error = null;
        $success = null;

        if ($recipient === '' || $recipient === '0') {
            $error = xlt("Recipient fax number is required");
        } else {
            try {
                // Get the document
                if ($isDocuments && !empty($docId)) {
                    $document = new \Document($docId);

                    try {
                        // Get decrypted document data
                        $data = $document->get_data();

                        if (empty($data)) {
                            $error = xlt("Document has no content");
                        } else {
                            // Create a temporary file with the decrypted content
                            $tempDir = sys_get_temp_dir();
                            $tempFile = tempnam($tempDir, 'sinch_fax_');

                            // Add appropriate extension based on MIME type
                            $extension = '.pdf';
                            $docMimeType = $document->get_mimetype();
                            if ($docMimeType === 'image/tiff' || $docMimeType === 'image/tif') {
                                $extension = '.tif';
                            } elseif ($docMimeType === 'image/png') {
                                $extension = '.png';
                            } elseif ($docMimeType === 'image/jpeg' || $docMimeType === 'image/jpg') {
                                $extension = '.jpg';
                            }

                            // Rename temp file with proper extension
                            $tempFileWithExt = $tempFile . $extension;
                            rename($tempFile, $tempFileWithExt);

                            // Write decrypted data to temp file
                            file_put_contents($tempFileWithExt, $data);

                            $options = [
                                'document_id' => $docId,
                            ];

                            if ($pid !== '') {
                                $options['patient_id'] = $pid;
                            }

                            // Send the fax
                            $result = $this->faxService->sendFax($recipient, [$tempFileWithExt], $options);

                            // Clean up temp file
                            unlink($tempFileWithExt);

                            $success = xlt("Fax sent successfully");
                        }
                    } catch (\Throwable $e) {
                        $error = xlt("Error retrieving document") . ": " . text($e->getMessage());
                    }
                } else {
                    $error = xlt("No document specified");
                }
            } catch (\Throwable $e) {
                $error = xlt("Error sending fax") . ": " . text($e->getMessage());
            }
        }

        // Re-render form with messages
        $documentName = '';
        if ($docId) {
            $document = new \Document($docId);
            $documentName = $document->get_name();
        }

        $content = $this->twig->render('fax/send-document.html.twig', [
            'document_name' => $documentName,
            'patient_id' => $pid,
            'is_documents' => $isDocuments,
            'doc_id' => $docId,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
            'error_message' => $error,
            'success_message' => $success,
        ]);

        return new Response($content);
    }
}
