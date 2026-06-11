<?php

/**
 * Fax List Controller - handles fax listing and sending
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Twig\Environment;

class FaxListController
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly GlobalConfig $config,
        private readonly FaxService $faxService,
        private readonly Environment $twig,
        private readonly SessionInterface $session
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
            'update_read_status' => $this->handleUpdateReadStatus($request),
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
        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''), $this->session)) {
            CsrfUtils::csrfNotVerified();
        }

        $to = (string)$request->request->get('to', '');
        $patientId = $request->request->get('patient_id');
        $coverPageId = $request->request->get('cover_page_id');

        if ($to === '' || $to === '0') {
            $_SESSION['fax_error'] = "Recipient number is required";
            return $this->redirect($request);
        }

        $uploadedFiles = $request->files->get('files');
        if (!is_array($uploadedFiles) || $uploadedFiles === []) {
            $_SESSION['fax_error'] = "At least one file is required";
            return $this->redirect($request);
        }

        $files = [];
        foreach ($uploadedFiles as $uploadedFile) {
            $isValidUpload = $uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                && $uploadedFile->isValid();
            if ($isValidUpload) {
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

            $faxId = isset($result['id']) && is_scalar($result['id']) ? (string)$result['id'] : 'Unknown';
            $_SESSION['fax_success'] = "Fax sent successfully! ID: " . $faxId;
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
        // Reconcile with Sinch API to detect missed webhooks
        try {
            $missedFaxes = $this->faxService->reconcileInboundFaxes();
            if ($missedFaxes !== []) {
                $this->logger->info("Reconciled " . count($missedFaxes) . " missed faxes");
            }
        } catch (\Throwable $e) {
            $this->logger->error("Reconciliation failed: " . $e->getMessage());
        }

        // Fetch configured fax numbers (informational only, failure doesn't block page)
        $faxNumbers = [];
        $faxNumbersError = null;
        if ($this->config->isConfigured()) {
            try {
                $result = $this->faxService->getConfiguredFaxNumbers();
                // Defensive: handle unexpected return types from mocks or errors
                // @phpstan-ignore function.alreadyNarrowedType
                if (is_array($result)) {
                    $faxNumbers = $result['numbers'] ?? []; // @phpstan-ignore nullCoalesce.offset
                    $faxNumbersError = $result['error'] ?? null;
                }
            } catch (\Throwable $e) {
                $this->logger->error("Error fetching fax numbers: " . $e->getMessage());
                $faxNumbersError = 'Unable to retrieve fax numbers';
            }
        }

        // Build filter conditions
        $whereClauses = [];
        $binds = [];

        // Direction filter
        $direction = $request->query->get('direction');
        if (!empty($direction) && in_array($direction, ['INBOUND', 'OUTBOUND'], true)) {
            $whereClauses[] = 'direction = ?';
            $binds[] = $direction;
        }

        // Show archived filter (default: exclude archived)
        $showArchived = $request->query->get('show_archived') === '1';
        if (!$showArchived) {
            $whereClauses[] = "read_status != 'archived'";
        }

        // Fetch faxes from database
        $faxes = [];
        try {
            $sql = 'SELECT * FROM oce_sinch_faxes';
            if ($whereClauses !== []) {
                $sql .= ' WHERE ' . implode(' AND ', $whereClauses);
            }
            $sql .= ' ORDER BY created_at DESC LIMIT 50';

            $faxes = QueryUtils::fetchRecords($sql, $binds);
        } catch (\Throwable $e) {
            $this->logger->error("Error loading faxes: " . $e->getMessage());
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
            'csrf_token' => CsrfUtils::collectCsrfToken($this->session),
            'assets_path' => $this->config->getAssetsStaticRelative(),
            'current_direction' => $direction,
            'show_archived' => $showArchived,
            'fax_numbers' => $faxNumbers,
            'fax_numbers_error' => $faxNumbersError,
            'is_configured' => $this->config->isConfigured(),
        ]);

        return new Response($content);
    }

    /**
     * Handle read status updates (mark_read, mark_unread, archive)
     */
    private function handleUpdateReadStatus(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirect($request);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''), $this->session)) {
            CsrfUtils::csrfNotVerified();
        }

        $newStatus = $request->request->get('read_status');
        if (!in_array($newStatus, ['unread', 'read', 'archived'], true)) {
            $_SESSION['fax_error'] = 'Invalid read status';
            return $this->redirect($request);
        }

        // Get fax IDs - can be single or multiple (bulk action)
        $faxIds = $request->request->all('fax_ids');
        if ($faxIds === []) {
            $singleId = $request->request->get('fax_id');
            if ($singleId) {
                $faxIds = [$singleId];
            }
        }

        if ($faxIds === []) {
            $_SESSION['fax_error'] = 'No faxes selected';
            return $this->redirect($request);
        }

        // Sanitize IDs to integers
        $faxIds = array_map(static fn(mixed $id): int => is_numeric($id) ? (int) $id : 0, $faxIds);
        $faxIds = array_filter($faxIds, fn($id): bool => $id > 0);

        if ($faxIds === []) {
            $_SESSION['fax_error'] = 'Invalid fax IDs';
            return $this->redirect($request);
        }

        try {
            $placeholders = implode(',', array_fill(0, count($faxIds), '?'));
            $sql = "UPDATE oce_sinch_faxes SET read_status = ?, updated_at = NOW() WHERE id IN ({$placeholders})";
            $binds = array_merge([$newStatus], $faxIds);
            QueryUtils::sqlStatementThrowException($sql, $binds);

            $count = count($faxIds);
            $statusLabel = match ($newStatus) {
                'read' => 'read',
                'unread' => 'unread',
                'archived' => 'archived',
            };
            $_SESSION['fax_success'] = "Marked {$count} fax(es) as {$statusLabel}";
        } catch (\Throwable $e) {
            $_SESSION['fax_error'] = 'Error updating fax status: ' . $e->getMessage();
        }

        return $this->redirect($request);
    }

    /**
     * Handle moving fax to patient document tree
     */
    private function handleMoveToPatient(Request $request): Response
    {
        if (!$request->isMethod('POST')) {
            return $this->redirect($request);
        }

        if (!CsrfUtils::verifyCsrfToken($request->request->get('csrf_token', ''), $this->session)) {
            CsrfUtils::csrfNotVerified();
        }

        $faxId = (int)$request->request->get('fax_id', 0);
        $patientId = (int)$request->request->get('patient_id', 0);

        if ($faxId === 0 || $patientId === 0) {
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
        $scriptNameParam = $request->server->get('SCRIPT_NAME');
        $scriptName = is_string($scriptNameParam) ? $scriptNameParam
            : '/interface/modules/custom_modules/oce-module-sinch-fax/public/index.php';
        $uri = $queryString !== '' ? $scriptName . '?' . $queryString : $scriptName;

        return new RedirectResponse($uri);
    }
}
