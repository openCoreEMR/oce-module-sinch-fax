<?php

/**
 * Exception thrown when access to a fax is denied
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Exception;

class FaxAccessDeniedException extends FaxException
{
    public function getStatusCode(): int
    {
        return 403;
    }
}
