<?php

/**
 * Fax List Controller - handles fax listing and sending
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

class FaxListController
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly FaxService $faxService,
        private readonly Environment $twig
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Dispatch action to appropriate method
     */
    public function dispatch(string $action): Response
    {
        // Note: Session is already started by OpenEMR's globals.php
        $request = Request::createFromGlobals();

        return match ($action) {
            'send' => $this->handleSendFax($request),
            'move_to_patient' => $this->handleMoveToPatient($request),
            'list' => $this->showFaxList($request),
            default => $this->showFaxList($request),
        };
    }

    /**
     * Handle fax sending from form submission
     */
    private function handleSendFax(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirect($request);
        }

        // Verify CSRF token
        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            CsrfUtils::csrfNotVerified();
        }

        $to = (string)$request->request->get('to', '');
        $patientId = $request->request->get('patient_id');
        $coverPageId = $request->request->get('cover_page_id');

        if (empty($to)) {
            $_SESSION['fax_error'] = "Recipient number is required";
            return $this->redirect($request);
        }

        $uploadedFiles = $request->files->get('files');
        if (!$uploadedFiles || empty($uploadedFiles[0])) {
            $_SESSION['fax_error'] = "At least one file is required";
            return $this->redirect($request);
        }

        $files = [];
        foreach ($uploadedFiles as $uploadedFile) {
            if ($uploadedFile->isValid()) {
                $files[] = [
                    'path' => $uploadedFile->getPathname(),
                    'filename' => $uploadedFile->getClientOriginalName()
                ];
            }
        }

        try {
            $result = $this->faxService->sendFax($to, $files, [
                'patient_id' => $patientId,
                'user_id' => $_SESSION['authUserID'] ?? null,
                'coverPageId' => $coverPageId,
            ]);

            $_SESSION['fax_success'] = "Fax sent successfully! ID: " . ($result['id'] ?? 'Unknown');
        } catch (\Throwable $e) {
            $_SESSION['fax_error'] = "Error sending fax: " . $e->getMessage();
        }

        return $this->redirect($request);
    }

    /**
     * Show fax list with filters
     */
    private function showFaxList(Request $request): Response
    {
        // Build filter conditions
        $whereClauses = [];
        $binds = [];

        $direction = $request->query->get('direction');
        if (!empty($direction)) {
            $whereClauses[] = "direction = ?";
            $binds[] = $direction;
        }

        $status = $request->query->get('status');
        if (!empty($status)) {
            $whereClauses[] = "status = ?";
            $binds[] = $status;
        }

        // Fetch faxes from database
        $faxes = [];
        try {
            $sql = "SELECT * FROM oce_sinch_faxes";
            if (!empty($whereClauses)) {
                $sql .= " WHERE " . implode(" AND ", $whereClauses);
            }
            $sql .= " ORDER BY created_at DESC LIMIT 50";

            $faxes = QueryUtils::fetchRecords($sql, $binds);

            // Update status for any faxes that are still in progress
            if ($this->config->isStatusPollingEnabled()) {
                foreach ($faxes as &$fax) {
                    $shouldPollFax = ($fax['status'] === 'IN_PROGRESS') ||
                                   ($fax['status'] === 'FAILURE' && empty($fax['error_message']));

                    if ($shouldPollFax && !empty($fax['sinch_fax_id'])) {
                        try {
                            $updatedFax = $this->faxService->getFax($fax['sinch_fax_id']);
                            if (isset($updatedFax['status'])) {
                                $hasChanges = ($updatedFax['status'] !== $fax['status']) ||
                                            (isset($updatedFax['numberOfPages']) &&
                                                $updatedFax['numberOfPages'] != $fax['num_pages']) ||
                                            (!empty($updatedFax['errorMessage']) &&
                                                empty($fax['error_message']));

                                if ($hasChanges) {
                                    $updateSql = "UPDATE oce_sinch_faxes SET status = ?, num_pages = ?, " .
                                        "error_code = ?, error_message = ?, updated_at = NOW() WHERE id = ?";
                                    QueryUtils::sqlStatementThrowException($updateSql, [
                                        $updatedFax['status'],
                                        $updatedFax['numberOfPages'] ?? 0,
                                        $updatedFax['errorCode'] ?? null,
                                        $updatedFax['errorMessage'] ?? null,
                                        $fax['id']
                                    ]);
                                    $fax['status'] = $updatedFax['status'];
                                    $fax['num_pages'] = $updatedFax['numberOfPages'] ?? 0;
                                    $fax['error_message'] = $updatedFax['errorMessage'] ?? '';
                                }
                            }
                        } catch (\Throwable $e) {
                            error_log("Error updating fax status for {$fax['sinch_fax_id']}: " . $e->getMessage());
                        }
                    }
                }
                unset($fax);
            }
        } catch (\Throwable $e) {
            error_log("Error loading faxes: " . $e->getMessage());
        }

        // Get flash messages
        $successMessage = $_SESSION['fax_success'] ?? null;
        $errorMessage = $_SESSION['fax_error'] ?? null;
        unset($_SESSION['fax_success'], $_SESSION['fax_error']);

        // Render template
        $content = $this->twig->render('fax/list.html.twig', [
            'faxes' => $faxes,
            'success_message' => $successMessage,
            'error_message' => $errorMessage,
            'csrf_token' => CsrfUtils::collectCsrfToken(),
            'assets_path' => $this->config->getAssetsStaticRelative(),
        ]);

        return new Response($content);
    }

    /**
     * Handle moving fax to patient document tree
     */
    private function handleMoveToPatient(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirect($request);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''))) {
            CsrfUtils::csrfNotVerified();
        }

        $faxId = (int)$request->request->get('fax_id', 0);
        $patientId = (int)$request->request->get('patient_id', 0);

        if (empty($faxId) || empty($patientId)) {
            $_SESSION['fax_error'] = "Missing required parameters";
            return $this->redirect($request);
        }

        try {
            $documentId = $this->faxService->moveToPatientDocuments($faxId, $patientId);
            $_SESSION['fax_success'] = "Fax moved to patient chart successfully! " .
                                      "Document ID: {$documentId}. " .
                                      "Look in the patient's 'Received Faxes' documents category.";
        } catch (\Throwable $e) {
            $_SESSION['fax_error'] = "Error moving fax: " . $e->getMessage();
        }

        return $this->redirect($request);
    }

    /**
     * Redirect to list page
     */
    private function redirect(Request $request): RedirectResponse
    {
        // Build clean URL without action parameter to avoid redirect loop
        $queryParams = $request->query->all();
        unset($queryParams['action']); // Remove action to show list

        $queryString = http_build_query($queryParams);
        // Use the actual script name, not getPathInfo() which may be wrong in OpenEMR
        $scriptName = $request->server->get(
            'SCRIPT_NAME',
            '/interface/modules/custom_modules/oce-module-sinch-fax/public/index.php'
        );
        $uri = $queryString ? $scriptName . '?' . $queryString : $scriptName;

        return new RedirectResponse($uri);
    }
}
