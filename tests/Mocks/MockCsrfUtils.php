<?php

/**
 * Mock CsrfUtils for testing
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Csrf;

use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Mock CsrfUtils to avoid CSRF checks during tests.
 *
 * Signatures are kept identical to the real oce-810
 * OpenEMR\Common\Csrf\CsrfUtils (which takes a SessionInterface) so this
 * stub mirrors the host symbol's contract — see the OCE PHP conventions on
 * test doubles that shadow host symbols.
 */
class CsrfUtils
{
    private static bool $verifyResult = true;

    public static function collectCsrfToken(SessionInterface $session, string $subject = 'default'): string
    {
        return 'test-csrf-token';
    }

    public static function verifyCsrfToken($token, SessionInterface $session, string $subject = 'default'): bool
    {
        return self::$verifyResult;
    }

    public static function csrfNotVerified(
        bool $toScreen = true,
        bool $toLog = true,
        ?callable $beforeExit = null,
    ): void {
        throw new \Exception('CSRF token verification failed');
    }

    public static function setVerifyResult(bool $result): void
    {
        self::$verifyResult = $result;
    }

    public static function reset(): void
    {
        self::$verifyResult = true;
    }
}
