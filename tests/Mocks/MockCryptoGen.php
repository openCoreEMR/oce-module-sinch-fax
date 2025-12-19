<?php

/**
 * Mock CryptoGen for testing
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenEMR\Common\Crypto;

/**
 * Mock CryptoGen to avoid encryption/decryption during tests
 */
class CryptoGen
{
    public function __construct()
    {
    }

    public function encryptStandard(string $plaintext): string
    {
        // For testing, just base64 encode to simulate encryption
        return base64_encode($plaintext);
    }

    /**
     * @return string|false
     */
    public function decryptStandard(string $ciphertext): string|false
    {
        // For testing, just base64 decode
        $decoded = base64_decode($ciphertext, true);
        return $decoded !== false ? $decoded : false;
    }
}
