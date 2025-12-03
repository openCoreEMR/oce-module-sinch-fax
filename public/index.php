<?php

/**
 * Main interface for Sinch Fax module
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../../../../globals.php';

use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\Service\CoverPageService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Common\Database\QueryUtils;

$config = new GlobalConfig();
$faxService = new FaxService($config);
$coverPageService = new CoverPageService($config);

// Initialize session for flash messages if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$action = $_GET['action'] ?? 'list';

// Handle cover page actions
if ($action === 'upload_cover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for POST requests
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $name = $_POST['cover_name'] ?? '';

    if (empty($name)) {
        $_SESSION['fax_error'] = "Cover page name is required";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!isset($_FILES['cover_file']) || $_FILES['cover_file']['error'] !== UPLOAD_ERR_OK) {
        $_SESSION['fax_error'] = "Please select a PDF file to upload";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    try {
        $coverPageService->uploadCoverPage(
            $name,
            $_FILES['cover_file']['tmp_name'],
            $_FILES['cover_file']['name']
        );
        $_SESSION['fax_success'] = "Cover page uploaded successfully!";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (\Exception $e) {
        $_SESSION['fax_error'] = "Error uploading cover page: " . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if ($action === 'delete_cover' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for POST requests
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }

    $coverId = $_POST['cover_id'] ?? 0;

    try {
        $coverPageService->deleteCoverPage((int)$coverId);
        $_SESSION['fax_success'] = "Cover page deleted successfully!";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (\Exception $e) {
        $_SESSION['fax_error'] = "Error deleting cover page: " . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for POST requests
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $to = $_POST['to'] ?? '';
    $patientId = $_POST['patient_id'] ?? null;
    $coverPageId = $_POST['cover_page_id'] ?? null;

    if (empty($to)) {
        $_SESSION['fax_error'] = "Recipient number is required";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        $_SESSION['fax_error'] = "At least one file is required";
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }

    $files = [];
    foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
            $files[] = [
                'path' => $tmpName,
                'filename' => $_FILES['files']['name'][$key]
            ];
        }
    }

    try {
        $result = $faxService->sendFax($to, $files, [
            'patient_id' => $patientId,
            'user_id' => $_SESSION['authUserID'] ?? null,
            'coverPageId' => $coverPageId,
        ]);

        // Store success message and redirect to list view
        $_SESSION['fax_success'] = "Fax sent successfully! ID: " . ($result['id'] ?? 'Unknown');
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    } catch (\Exception $e) {
        $_SESSION['fax_error'] = "Error sending fax: " . $e->getMessage();
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

// Poll for incoming faxes if enabled
if ($config->isIncomingPollingEnabled()) {
    try {
        $faxService->pollIncomingFaxes();
    } catch (\Exception $e) {
        error_log("Error polling for incoming faxes: " . $e->getMessage());
    }
}

$filters = [];
if (isset($_GET['direction'])) {
    $filters['direction'] = $_GET['direction'];
}
if (isset($_GET['status'])) {
    $filters['status'] = $_GET['status'];
}

$faxes = [];
try {
    $sql = "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 50";
    $faxes = QueryUtils::fetchRecords($sql);

    // Update status for any faxes that are still in progress
    // Only poll if: callbacks are disabled (localhost) OR polling is explicitly enabled
    $shouldPoll = !$config->hasPublicCallbackUrl() || $config->isStatusPollingEnabled();

    if ($shouldPoll) {
        foreach ($faxes as &$fax) {
            // Poll if status is IN_PROGRESS, or if status is FAILURE but we don't have error details yet
            $shouldPollFax = ($fax['status'] === 'IN_PROGRESS') ||
                           ($fax['status'] === 'FAILURE' && empty($fax['error_message']));

            if ($shouldPollFax && !empty($fax['sinch_fax_id'])) {
                try {
                    // Query Sinch API for latest status
                    $updatedFax = $faxService->getFax($fax['sinch_fax_id']);
                    if (isset($updatedFax['status'])) {
                        // Check if anything changed (status, pages, or error details)
                        $hasChanges = ($updatedFax['status'] !== $fax['status']) ||
                                    (isset($updatedFax['numberOfPages']) &&
                                        $updatedFax['numberOfPages'] != $fax['num_pages']) ||
                                    (!empty($updatedFax['errorMessage']) &&
                                        empty($fax['error_message']));

                        if ($hasChanges) {
                            // Update database with new status and error fields
                            $updateSql = "UPDATE oce_sinch_faxes SET status = ?, num_pages = ?, " .
                                "error_code = ?, error_message = ?, updated_at = NOW() WHERE id = ?";
                            QueryUtils::sqlStatementThrowException($updateSql, [
                                $updatedFax['status'],
                                $updatedFax['numberOfPages'] ?? 0,
                                $updatedFax['errorCode'] ?? null,
                                $updatedFax['errorMessage'] ?? null,
                                $fax['id']
                            ]);
                            // Update the array for display
                            $fax['status'] = $updatedFax['status'];
                            $fax['num_pages'] = $updatedFax['numberOfPages'] ?? 0;
                            $fax['error_message'] = $updatedFax['errorMessage'] ?? '';
                        }
                    }
                } catch (\Exception $e) {
                    error_log("Error updating fax status for {$fax['sinch_fax_id']}: " . $e->getMessage());
                }
            }
        }
        unset($fax); // Break reference
    }
} catch (\Exception $e) {
    error_log("Error loading faxes: " . $e->getMessage());
}

// Load cover pages
$coverPages = [];
try {
    $coverPages = $coverPageService->listCoverPages(true);
} catch (\Exception $e) {
    error_log("Error loading cover pages: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OpenCoreEMR Sinch Fax'); ?></title>
    <link
        rel="stylesheet"
        href="<?php echo $config->getAssetsStaticRelative(); ?>/bootstrap/dist/css/bootstrap.min.css"
    >
</head>
<body>
    <div class="container-fluid mt-3">
        <h2><?php echo xlt('OpenCoreEMR Sinch Fax'); ?></h2>

        <?php
        // Display flash messages
        if (isset($_SESSION['fax_success'])) {
            echo '<div class="alert alert-success alert-dismissible fade show" role="alert">' .
                 text($_SESSION['fax_success']) .
                 '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' .
                 '<span aria-hidden="true">&times;</span></button></div>';
            unset($_SESSION['fax_success']);
        }
        if (isset($_SESSION['fax_error'])) {
            echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">' .
                 text($_SESSION['fax_error']) .
                 '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' .
                 '<span aria-hidden="true">&times;</span></button></div>';
            unset($_SESSION['fax_error']);
        }

        // Display configuration status
        $webhooksEnabled = $config->isWebhooksEnabled();
        $pollingEnabled = $config->isIncomingPollingEnabled();
        $lastPollTime = $config->getLastPollTime();
        ?>
        <div class="alert alert-info">
            <strong><?php echo xlt('Configuration Status'); ?>:</strong>
            <ul class="mb-0">
                <li>
                    <strong><?php echo xlt('Webhooks'); ?>:</strong>
                    <?php if ($webhooksEnabled) : ?>
                        <span class="badge badge-success"><?php echo xlt('Enabled'); ?></span>
                    <?php else : ?>
                        <span class="badge badge-warning"><?php echo xlt('Disabled'); ?></span>
                    <?php endif; ?>
                </li>
                <?php if ($pollingEnabled) : ?>
                <li>
                    <strong><?php echo xlt('Incoming Fax Polling'); ?>:</strong>
                    <span class="badge badge-success"><?php echo xlt('Enabled'); ?></span>
                    <?php if ($lastPollTime) : ?>
                        <br><small><?php echo xlt('Last poll'); ?>: <?php echo text($lastPollTime); ?></small>
                    <?php endif; ?>
                </li>
                <?php endif; ?>
            </ul>
        </div>

        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-toggle="tab" href="#list"><?php echo xlt('Fax List'); ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#send"><?php echo xlt('Send Fax'); ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#cover-pages"><?php echo xlt('Cover Pages'); ?></a>
            </li>
        </ul>

        <div class="tab-content mt-3">
            <div id="list" class="tab-pane fade show active">
                <h4><?php echo xlt('Recent Faxes'); ?></h4>
                <table class="table table-striped">
                    <thead>
                        <tr>
                            <th><?php echo xlt('Direction'); ?></th>
                            <th><?php echo xlt('Fax ID'); ?></th>
                            <th><?php echo xlt('From'); ?></th>
                            <th><?php echo xlt('To'); ?></th>
                            <th><?php echo xlt('Status'); ?></th>
                            <th><?php echo xlt('Pages'); ?></th>
                            <th><?php echo xlt('Error'); ?></th>
                            <th><?php echo xlt('Date'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($faxes as $fax) : ?>
                        <tr>
                            <td><?php echo text($fax['direction']); ?></td>
                            <td><small><?php echo text($fax['sinch_fax_id'] ?? ''); ?></small></td>
                            <td><?php echo text($fax['from_number']); ?></td>
                            <td><?php echo text($fax['to_number']); ?></td>
                            <td><?php echo text($fax['status']); ?></td>
                            <td><?php echo text($fax['num_pages']); ?></td>
                            <td><small><?php echo text($fax['error_message'] ?? ''); ?></small></td>
                            <td><?php echo text($fax['created_at']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div id="send" class="tab-pane fade">
                <h4><?php echo xlt('Send a Fax'); ?></h4>
                <form
                    method="post"
                    enctype="multipart/form-data"
                    action="?action=send"
                >
                    <input type="hidden" name="csrf_token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">

                    <div class="form-group">
                        <label for="to"><?php echo xlt('Recipient Fax Number'); ?></label>
                        <input type="text" class="form-control" id="to" name="to" placeholder="+1234567890" required>
                    </div>

                    <div class="form-group">
                        <label for="files"><?php echo xlt('Files to Fax'); ?></label>
                        <input type="file" class="form-control-file" id="files" name="files[]" multiple required>
                    </div>

                    <div class="form-group">
                        <label for="cover_page_id"><?php echo xlt('Cover Page (optional)'); ?></label>
                        <select class="form-control" id="cover_page_id" name="cover_page_id">
                            <option value=""><?php echo xlt('-- No Cover Page --'); ?></option>
                            <?php foreach ($coverPages as $coverPage) : ?>
                                <option value="<?php echo attr($coverPage['id']); ?>">
                                    <?php echo text($coverPage['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="form-text text-muted">
                            <?php echo xlt('Select a cover page template to attach to your fax'); ?>
                        </small>
                    </div>

                    <div class="form-group">
                        <label for="patient_id"><?php echo xlt('Patient ID (optional)'); ?></label>
                        <input type="number" class="form-control" id="patient_id" name="patient_id">
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo xlt('Send Fax'); ?></button>
                </form>
            </div>

            <div id="cover-pages" class="tab-pane fade">
                <h4><?php echo xlt('Cover Page Management'); ?></h4>
                
                <div class="card mb-4">
                    <div class="card-header">
                        <h5><?php echo xlt('Upload New Cover Page'); ?></h5>
                    </div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" action="?action=upload_cover">
                            <input type="hidden" name="csrf_token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                            
                            <div class="form-group">
                                <label for="cover_name"><?php echo xlt('Cover Page Name'); ?></label>
                                <input type="text" class="form-control" id="cover_name" name="cover_name" required>
                                <small class="form-text text-muted">
                                    <?php echo xlt('A descriptive name for this cover page template'); ?>
                                </small>
                            </div>

                            <div class="form-group">
                                <label for="cover_file"><?php echo xlt('PDF File'); ?></label>
                                <input type="file" class="form-control-file" id="cover_file" name="cover_file" accept="application/pdf,.pdf" required>
                                <small class="form-text text-muted">
                                    <?php echo xlt('Upload a PDF file to use as a cover page template'); ?>
                                </small>
                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-upload"></i> <?php echo xlt('Upload Cover Page'); ?>
                            </button>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header">
                        <h5><?php echo xlt('Existing Cover Pages'); ?></h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($coverPages)) : ?>
                            <p class="text-muted"><?php echo xlt('No cover pages uploaded yet.'); ?></p>
                        <?php else : ?>
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th><?php echo xlt('Name'); ?></th>
                                        <th><?php echo xlt('Created'); ?></th>
                                        <th><?php echo xlt('Actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($coverPages as $coverPage) : ?>
                                        <tr>
                                            <td><?php echo text($coverPage['name']); ?></td>
                                            <td><?php echo text($coverPage['created_at']); ?></td>
                                            <td>
                                                <form method="post" action="?action=delete_cover" style="display: inline;" 
                                                      onsubmit="return confirm('<?php echo xla('Are you sure you want to delete this cover page?'); ?>');">
                                                    <input type="hidden" name="csrf_token" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>">
                                                    <input type="hidden" name="cover_id" value="<?php echo attr($coverPage['id']); ?>">
                                                    <button type="submit" class="btn btn-sm btn-danger">
                                                        <i class="fa fa-trash"></i> <?php echo xlt('Delete'); ?>
                                                    </button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="alert alert-info mt-4">
                    <h6><?php echo xlt('Template Variables Support'); ?></h6>
                    <p><?php echo xlt('Cover pages can include dynamic template variables that will be replaced with actual values when sending faxes:'); ?></p>
                    <ul>
                        <li><code>{{from}}</code> - <?php echo xlt('Sender name/facility'); ?></li>
                        <li><code>{{to}}</code> - <?php echo xlt('Recipient name'); ?></li>
                        <li><code>{{date}}</code> - <?php echo xlt('Current date'); ?></li>
                        <li><code>{{time}}</code> - <?php echo xlt('Current time'); ?></li>
                        <li><code>{{patient}}</code> - <?php echo xlt('Patient name (if linked)'); ?></li>
                        <li><code>{{pages}}</code> - <?php echo xlt('Number of pages'); ?></li>
                        <li><code>{{subject}}</code> - <?php echo xlt('Fax subject/notes'); ?></li>
                    </ul>
                    <p class="mb-0"><small><?php echo xlt('Note: Template variable substitution requires PDF editing capabilities. For now, cover pages are attached as-is.'); ?></small></p>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo $config->getAssetsStaticRelative(); ?>/jquery/dist/jquery.min.js"></script>
    <script src="<?php echo $config->getAssetsStaticRelative(); ?>/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
