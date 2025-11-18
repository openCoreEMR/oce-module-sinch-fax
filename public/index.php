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
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Csrf\CsrfUtils;

$config = new GlobalConfig();
$faxService = new FaxService($config);

$action = $_GET['action'] ?? 'list';

if ($action === 'send' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token for POST requests
    if (!CsrfUtils::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        CsrfUtils::csrfNotVerified();
    }
    $to = $_POST['to'] ?? '';
    $patientId = $_POST['patient_id'] ?? null;
    $coverPageId = $_POST['cover_page_id'] ?? null;

    if (empty($to)) {
        echo "Error: Recipient number is required";
        exit;
    }

    if (!isset($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        echo "Error: At least one file is required";
        exit;
    }

    $files = [];
    foreach ($_FILES['files']['tmp_name'] as $key => $tmpName) {
        if ($_FILES['files']['error'][$key] === UPLOAD_ERR_OK) {
            $files[] = $tmpName;
        }
    }

    try {
        $result = $faxService->sendFax($to, $files, [
            'patient_id' => $patientId,
            'user_id' => $_SESSION['authUserID'] ?? null,
            'coverPageId' => $coverPageId,
        ]);

        echo "Fax sent successfully! ID: " . ($result['id'] ?? 'Unknown');
    } catch (\Exception $e) {
        echo "Error sending fax: " . $e->getMessage();
    }
    exit;
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
    $result = sqlStatement($sql);
    while ($row = sqlFetchArray($result)) {
        $faxes[] = $row;
    }

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
                                    (isset($updatedFax['numberOfPages']) && $updatedFax['numberOfPages'] != $fax['num_pages']) ||
                                    (!empty($updatedFax['errorMessage']) && empty($fax['error_message']));

                        if ($hasChanges) {
                            // Update database with new status and error fields
                            $updateSql = "UPDATE oce_sinch_faxes SET status = ?, num_pages = ?, error_code = ?, error_message = ?, updated_at = NOW() WHERE id = ?";
                            sqlStatement($updateSql, [
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

?>
<!DOCTYPE html>
<html>
<head>
    <title><?php echo xlt('OpenCoreEMR Sinch Fax'); ?></title>
    <link
        rel="stylesheet"
        href="<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap/dist/css/bootstrap.min.css"
    >
</head>
<body>
    <div class="container-fluid mt-3">
        <h2><?php echo xlt('OpenCoreEMR Sinch Fax'); ?></h2>

        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" data-toggle="tab" href="#list"><?php echo xlt('Fax List'); ?></a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-toggle="tab" href="#send"><?php echo xlt('Send Fax'); ?></a>
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
                    action="?action=send&csrf_token=<?php echo attr(CsrfUtils::collectCsrfToken()); ?>"
                >
                    <div class="form-group">
                        <label for="to"><?php echo xlt('Recipient Fax Number'); ?></label>
                        <input type="text" class="form-control" id="to" name="to" placeholder="+1234567890" required>
                    </div>

                    <div class="form-group">
                        <label for="files"><?php echo xlt('Files to Fax'); ?></label>
                        <input type="file" class="form-control-file" id="files" name="files[]" multiple required>
                    </div>

                    <div class="form-group">
                        <label for="patient_id"><?php echo xlt('Patient ID (optional)'); ?></label>
                        <input type="number" class="form-control" id="patient_id" name="patient_id">
                    </div>

                    <button type="submit" class="btn btn-primary"><?php echo xlt('Send Fax'); ?></button>
                </form>
            </div>
        </div>
    </div>

    <script src="<?php echo $GLOBALS['assets_static_relative']; ?>/jquery/dist/jquery.min.js"></script>
    <script src="<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
