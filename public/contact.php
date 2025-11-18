<?php

/**
 * Sinch Fax Contact Dialog for Document Viewer
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

$sessionAllowWrite = true;
require_once(__DIR__ . "/../../../../globals.php");
require_once(__DIR__ . "/../../../../../library/classes/Document.class.php");

use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

// Check if module is enabled
if (empty($GLOBALS['oce_sinch_fax_enabled'])) {
    die("<h3>" . xlt("Sinch Fax module is not enabled") . "</h3>");
}

// Get request parameters
$isDocuments = (int)($_REQUEST['isDocuments'] ?? 0);
$filePath = $_REQUEST['file'] ?? '';
$mimeType = $_REQUEST['mime'] ?? '';
$docId = $_REQUEST['docid'] ?? '';
$pid = $_REQUEST['pid'] ?? '';

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

// Handle form submission
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!CsrfUtils::verifyCsrfToken($_POST["csrf_token_form"] ?? '')) {
        die(xlt("Authentication Error"));
    }

    $recipient = $_POST['recipient'] ?? '';

    if (empty($recipient)) {
        $error = xlt("Recipient fax number is required");
    } else {
        try {
            $faxService = new FaxService();

            // Get the document and file path
            if ($isDocuments && !empty($document)) {
                // Build the filesystem path using public methods
                $filepath = $document->get_url_filepath();
                $from_all = explode("/", (string) $filepath);
                $from_filename = array_pop($from_all);
                $from_pathname_array = [];
                for ($i = 0; $i < $document->get_path_depth(); $i++) {
                    $from_pathname_array[] = array_pop($from_all);
                }
                $from_pathname_array = array_reverse($from_pathname_array);
                $from_pathname = implode("/", $from_pathname_array);
                $fullPath = $GLOBALS['OE_SITE_DIR'] . '/documents/' . $from_pathname . '/' . $from_filename;

                if (file_exists($fullPath)) {
                    $options = [];
                    if (!empty($pid)) {
                        $options['patient_id'] = $pid;
                    }
                    if (!empty($docId)) {
                        $options['document_id'] = $docId;
                    }

                    $result = $faxService->sendFax($recipient, [$fullPath], $options);
                    $success = xlt("Fax sent successfully");

                    // Close dialog after success
                    echo "<script>setTimeout(function() { dlgclose(); }, 2000);</script>";
                } else {
                    $error = xlt("Document file not found") . " (Path: " . text($fullPath) . ")";
                }
            } else {
                $error = xlt("No document specified");
            }
        } catch (\Exception $e) {
            $error = xlt("Error sending fax") . ": " . text($e->getMessage());
        }
    }
}

?>
<!DOCTYPE html>
<html lang="">
<head>
    <title><?php echo xlt('Send Fax via Sinch'); ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php Header::setupHeader(); ?>
    <style>
        .form-group {
            margin-bottom: 1rem;
        }
        .alert {
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid">
        <h4><?php echo xlt('Send Fax via Sinch'); ?></h4>

        <?php if (!empty($error)) : ?>
            <div class="alert alert-danger" role="alert">
                <?php echo text($error); ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($success)) : ?>
            <div class="alert alert-success" role="alert">
                <?php echo text($success); ?>
            </div>
        <?php endif; ?>

        <form method="post" id="fax-form">
            <input type="hidden" name="csrf_token_form" value="<?php echo attr(CsrfUtils::collectCsrfToken()); ?>" />

            <div class="form-group">
                <label for="document_name"><?php echo xlt('Document'); ?>:</label>
                <input type="text" class="form-control" id="document_name" value="<?php echo attr($documentName); ?>" readonly>
            </div>

            <?php if (!empty($pid)) : ?>
            <div class="form-group">
                <label for="patient_id"><?php echo xlt('Patient ID'); ?>:</label>
                <input type="text" class="form-control" id="patient_id" value="<?php echo attr($pid); ?>" readonly>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="recipient"><?php echo xlt('Recipient Fax Number'); ?>: <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="recipient" name="recipient"
                       placeholder="<?php echo xla('Enter fax number (e.g., +1234567890)'); ?>"
                       required autocomplete="off">
                <small class="form-text text-muted">
                    <?php echo xlt('Enter fax number in E.164 format (e.g., +1234567890)'); ?>
                </small>
            </div>

            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <i class="fa fa-fax"></i> <?php echo xlt('Send Fax'); ?>
                </button>
                <button type="button" class="btn btn-secondary" onclick="dlgclose();">
                    <?php echo xlt('Cancel'); ?>
                </button>
            </div>
        </form>
    </div>

    <script>
        $(function() {
            // Focus on recipient field when dialog opens
            $('#recipient').focus();

            // Handle form submission
            $('#fax-form').on('submit', function(e) {
                const recipient = $('#recipient').val().trim();
                if (!recipient) {
                    e.preventDefault();
                    alert(<?php echo xlj('Please enter a recipient fax number'); ?>);
                    return false;
                }
            });
        });
    </script>
</body>
</html>
