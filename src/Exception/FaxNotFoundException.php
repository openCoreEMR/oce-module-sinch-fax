<?php

/**
 * Exception thrown when a fax or fax file is not found
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Exception;

class FaxNotFoundException extends FaxException
{
    public function getStatusCode(): int
    {
        return 404;
    }
}
