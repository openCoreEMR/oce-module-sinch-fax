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

use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenEMR\Common\Csrf\CsrfUtils;
use OpenEMR\Core\Header;

$config = new GlobalConfig();

// Check if module is enabled
if (!$config->isEnabled()) {
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
                        $extension = '.pdf'; // Default to PDF
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

                        $options = [];
                        if (!empty($pid)) {
                            $options['patient_id'] = $pid;
                        }
                        if (!empty($docId)) {
                            $options['document_id'] = $docId;
                        }

                        // Send the fax
                        $result = $faxService->sendFax($recipient, [$tempFileWithExt], $options);

                        // Clean up temp file
                        unlink($tempFileWithExt);

                        $success = xlt("Fax sent successfully");

                        // Close dialog after success
                        echo "<script>setTimeout(function() { dlgclose(); }, 2000);</script>";
                    }
                } catch (\Exception $e) {
                    $error = xlt("Error retrieving document") . ": " . text($e->getMessage());
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
    <title><?php echo xlt('Send Fax via OpenCoreEMR Sinch'); ?></title>
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
        <h4><?php echo xlt('Send Fax via OpenCoreEMR Sinch'); ?></h4>

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
                <input
                    type="text"
                    class="form-control"
                    id="document_name"
                    value="<?php echo attr($documentName); ?>"
                    readonly>
            </div>

            <?php if (!empty($pid)) : ?>
            <div class="form-group">
                <label for="patient_id"><?php echo xlt('Patient ID'); ?>:</label>
                <input type="text" class="form-control" id="patient_id" value="<?php echo attr($pid); ?>" readonly>
            </div>
            <?php endif; ?>

            <div class="form-group">
                <label for="recipient">
                    <?php echo xlt('Recipient Fax Number'); ?>: <span class="text-danger">*</span>
                </label>
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
