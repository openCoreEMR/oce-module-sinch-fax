<?php

/**
 * Mock PatientDocumentEvent for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Events\PatientDocuments;

class PatientDocumentEvent
{
    public const ACTIONS_RENDER_FAX_ANCHOR = 'patient.documents.actions.render.fax.anchor';
    public const JAVASCRIPT_READY_FAX_DIALOG = 'patient.documents.javascript.ready.fax.dialog';
}
