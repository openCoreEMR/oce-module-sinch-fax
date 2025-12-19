<?php

/**
 * Exception thrown when there is a configuration error
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Exception;

class FaxConfigurationException extends FaxException
{
    public function getStatusCode(): int
    {
        return 500;
    }
}
