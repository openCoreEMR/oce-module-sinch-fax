<?php

/**
 * Base exception class for fax-related errors
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Exception;

abstract class FaxException extends \RuntimeException implements FaxExceptionInterface
{
    /**
     * Get the HTTP status code for this exception
     */
    abstract public function getStatusCode(): int;
}
