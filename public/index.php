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
            if ($fax['status'] === 'IN_PROGRESS' && !empty($fax['sinch_fax_id'])) {
                try {
                    // Query Sinch API for latest status
                    $updatedFax = $faxService->getFax($fax['sinch_fax_id']);
                    if (isset($updatedFax['status']) && $updatedFax['status'] !== $fax['status']) {
                        // Update database with new status
                        $updateSql = "UPDATE oce_sinch_faxes SET status = ?, updated_at = NOW() WHERE id = ?";
                        sqlStatement($updateSql, [$updatedFax['status'], $fax['id']]);
                        // Update the array for display
                        $fax['status'] = $updatedFax['status'];
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
    <title><?php echo xlt('Sinch Fax'); ?></title>
    <link
        rel="stylesheet"
        href="<?php echo $GLOBALS['assets_static_relative']; ?>/bootstrap/dist/css/bootstrap.min.css"
    >
</head>
<body>
    <div class="container-fluid mt-3">
        <h2><?php echo xlt('Sinch Fax'); ?></h2>

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
