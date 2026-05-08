<?php

/**
 * Exception thrown when Sinch API communication fails
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenCoreEMR\Modules\SinchFax\Exception;

class FaxApiException extends FaxException
{
    public function getStatusCode(): int
    {
        return 502;
    }
}
