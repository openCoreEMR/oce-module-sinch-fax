#!/usr/bin/env php
<?php

/**
 * Test webhook script for Sinch Fax module
 *
 * Sends simulated Sinch webhook payloads to test the webhook endpoint locally.
 * Uses Guzzle HTTP client (already a module dependency).
 *
 * Usage:
 *   php tools/test-webhook.php <url> [event] [options]
 *
 * Events:
 *   incoming   - INCOMING_FAX event (default)
 *   completed  - FAX_COMPLETED with success
 *   failed     - FAX_COMPLETED with failure
 *   json       - Send as application/json
 *
 * Options:
 *   --fax-id=ID       Custom fax ID
 *   --from=NUMBER     From phone number
 *   --to=NUMBER       To phone number
 *   --pages=N         Number of pages
 *   --with-file=PATH  Include PDF attachment
 *   --help            Show this help
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2026 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

require_once __DIR__ . '/../vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Parse command line arguments
 *
 * @param array<int, string> $argv
 * @return array{url: string, event: string, options: array<string, string>}
 */
function parseArgs(array $argv): array
{
    $url = '';
    $event = 'incoming';
    $options = [
        'fax-id' => '',
        'from' => '+15551234567',
        'to' => '+15559876543',
        'pages' => '2',
        'with-file' => '',
    ];

    // Skip script name
    array_shift($argv);

    foreach ($argv as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            showHelp();
            exit(0);
        }

        if (str_starts_with($arg, '--')) {
            // Parse --option=value
            $parts = explode('=', substr($arg, 2), 2);
            $key = $parts[0];
            $value = $parts[1] ?? '';
            if (array_key_exists($key, $options)) {
                $options[$key] = $value;
            }
        } elseif (empty($url)) {
            $url = $arg;
        } elseif (in_array($arg, ['incoming', 'completed', 'failed', 'json'], true)) {
            $event = $arg;
        }
    }

    return ['url' => $url, 'event' => $event, 'options' => $options];
}

function showHelp(): void
{
    echo <<<'HELP'
Sinch Fax Webhook Tester

Usage:
  php tools/test-webhook.php <url> [event] [options]

Arguments:
  url       The webhook URL to send the payload to (required)
  event     Event type: incoming, completed, failed, json (default: incoming)

Options:
  --fax-id=ID       Custom fax ID (default: auto-generated)
  --from=NUMBER     From phone number (default: +15551234567)
  --to=NUMBER       To phone number (default: +15559876543)
  --pages=N         Number of pages (default: 2)
  --with-file=PATH  Path to PDF file to include as attachment
  --help, -h        Show this help message

Event Types:
  incoming    INCOMING_FAX - simulates receiving a fax
  completed   FAX_COMPLETED with success status
  failed      FAX_COMPLETED with failure status
  json        Send as application/json instead of multipart/form-data

Examples:
  php tools/test-webhook.php http://localhost:8080/webhook.php incoming
  php tools/test-webhook.php http://localhost:8080/webhook.php completed --fax-id=my-test-123
  php tools/test-webhook.php http://localhost:8080/webhook.php incoming --with-file=/path/to/test.pdf

HELP;
}

/**
 * Generate a unique fax ID
 */
function generateFaxId(): string
{
    return 'test-' . time() . '-' . bin2hex(random_bytes(4));
}

/**
 * Build fax data payload
 *
 * @param array<string, string> $options
 * @return array<string, mixed>
 */
function buildFaxData(string $faxId, array $options, string $direction, string $status): array
{
    $timestamp = gmdate('Y-m-d\TH:i:s\Z');

    $data = [
        'id' => $faxId,
        'projectId' => 'test-project',
        'serviceId' => 'test-service',
        'direction' => $direction,
        'from' => $direction === 'INBOUND' ? $options['from'] : $options['to'],
        'to' => $direction === 'INBOUND' ? $options['to'] : $options['from'],
        'status' => $status,
        'numberOfPages' => (int) $options['pages'],
        'createTime' => $timestamp,
        'completedTime' => $timestamp,
    ];

    if ($direction === 'INBOUND') {
        $data['contentUrl'] = "https://fax.sinch.com/v3/projects/test-project/faxes/{$faxId}/file";
    }

    if ($status === 'FAILURE') {
        $data['errorCode'] = 'NO_ANSWER';
        $data['errorMessage'] = 'Remote fax machine did not answer';
    }

    return $data;
}

/**
 * Send webhook request using Guzzle
 *
 * @param array<string, mixed> $payload
 * @return array{status: int, body: string}
 */
function sendWebhook(Client $client, string $url, string $event, array $payload, ?string $filePath = null): array
{
    try {
        if ($event === 'json') {
            // Send as JSON
            $response = $client->post($url, [
                'json' => [
                    'event' => 'INCOMING_FAX',
                    'fax' => $payload,
                ],
                'http_errors' => false,
            ]);
        } else {
            // Send as multipart form data
            $multipart = [
                [
                    'name' => 'event',
                    'contents' => match ($event) {
                        'incoming' => 'INCOMING_FAX',
                        'completed', 'failed' => 'FAX_COMPLETED',
                        default => 'INCOMING_FAX',
                    },
                ],
                [
                    'name' => 'fax',
                    'contents' => json_encode($payload),
                ],
            ];

            // Add file if specified
            if ($filePath !== null && file_exists($filePath)) {
                $multipart[] = [
                    'name' => 'file',
                    'contents' => fopen($filePath, 'r'),
                    'filename' => basename($filePath),
                ];
            }

            $response = $client->post($url, [
                'multipart' => $multipart,
                'http_errors' => false,
            ]);
        }

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getBody(),
        ];
    } catch (GuzzleException $e) {
        return [
            'status' => 0,
            'body' => 'Request failed: ' . $e->getMessage(),
        ];
    }
}

/**
 * Print colored output
 */
function output(string $message, string $color = 'default'): void
{
    $colors = [
        'default' => "\033[0m",
        'green' => "\033[32m",
        'yellow' => "\033[33m",
        'red' => "\033[31m",
        'cyan' => "\033[36m",
        'reset' => "\033[0m",
    ];

    $code = $colors[$color] ?? $colors['default'];
    echo $code . $message . $colors['reset'] . "\n";
}

// Main execution
$args = parseArgs($argv);

if (empty($args['url'])) {
    output("Error: URL is required", 'red');
    echo "\n";
    showHelp();
    exit(1);
}

$url = $args['url'];
$event = $args['event'];
$options = $args['options'];
$faxId = $options['fax-id'] ?: generateFaxId();
$filePath = $options['with-file'] ?: null;

// Validate file if specified
if ($filePath !== null && !file_exists($filePath)) {
    output("Error: File not found: {$filePath}", 'red');
    exit(1);
}

// Build payload based on event type
$direction = $event === 'incoming' || $event === 'json' ? 'INBOUND' : 'OUTBOUND';
$status = $event === 'failed' ? 'FAILURE' : 'COMPLETED';
$payload = buildFaxData($faxId, $options, $direction, $status);

// Display info
output("Sinch Webhook Test", 'cyan');
echo str_repeat('-', 40) . "\n";
output("URL:      {$url}", 'default');
output("Event:    {$event}", 'default');
output("Fax ID:   {$faxId}", 'default');
if ($filePath) {
    output("File:     {$filePath}", 'default');
}
echo "\n";

// Send request
$client = new Client(['timeout' => 30]);
$result = sendWebhook($client, $url, $event, $payload, $filePath);

// Display result
$statusCode = $result['status'];
$body = $result['body'];

if ($statusCode >= 200 && $statusCode < 300) {
    output("SUCCESS (HTTP {$statusCode})", 'green');
} elseif ($statusCode === 0) {
    output("FAILED - Connection error", 'red');
} else {
    output("WARNING (HTTP {$statusCode})", 'yellow');
}

echo "\nResponse:\n";
$decoded = json_decode($body, true);
if ($decoded !== null) {
    echo json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
} else {
    echo $body . "\n";
}

exit($statusCode >= 200 && $statusCode < 300 ? 0 : 1);
