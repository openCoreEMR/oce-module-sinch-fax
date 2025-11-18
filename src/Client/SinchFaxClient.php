<?php

/**
 * Sinch Fax API Client
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Client;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Logging\SystemLogger;

class SinchFaxClient
{
    private readonly Client $httpClient;
    private readonly SystemLogger $logger;
    private readonly string $baseUrl;
    private readonly string $projectId;
    private readonly string $authMethod;

    public function __construct(private readonly GlobalConfig $config)
    {
        $this->logger = new SystemLogger();
        $this->projectId = $config->getProjectId();
        $this->authMethod = $config->getAuthMethod();

        $region = $config->getRegion();
        $this->baseUrl = $region === 'global' ? 'https://fax.api.sinch.com' : "https://{$region}.fax.api.sinch.com";

        $this->httpClient = new Client([
            'base_uri' => $this->baseUrl,
            'timeout' => 30.0,
            'headers' => $this->getAuthHeaders(),
        ]);
    }

    /**
     * @return array<string, string>
     */
    private function getAuthHeaders(): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if ($this->authMethod === 'basic') {
            $apiKey = trim($this->config->getApiKey());
            $apiSecret = trim($this->config->getApiSecret());
            $credentials = base64_encode("{$apiKey}:{$apiSecret}");
            $headers['Authorization'] = "Basic {$credentials}";
        } elseif ($this->authMethod === 'oauth') {
            $token = trim($this->config->getOAuthToken());
            $headers['Authorization'] = "Bearer {$token}";
        }

        return $headers;
    }

    /**
     * Send a fax
     *
     * @param array<string, mixed> $params Fax parameters
     * @return array<string, mixed> Response from API
     * @throws \Exception
     */
    public function sendFax(array $params): array
    {
        try {
            $multipart = [];

            if (isset($params['to'])) {
                $multipart[] = ['name' => 'to', 'contents' => $params['to']];
            }

            if (isset($params['from'])) {
                $multipart[] = ['name' => 'from', 'contents' => $params['from']];
            }

            if (isset($params['files'])) {
                foreach ($params['files'] as $file) {
                    $multipart[] = [
                        'name' => 'file',
                        'contents' => fopen($file['path'], 'r'),
                        'filename' => $file['filename'] ?? basename((string) $file['path'])
                    ];
                }
            }

            if (isset($params['contentUrl'])) {
                $multipart[] = ['name' => 'contentUrl', 'contents' => $params['contentUrl']];
            }

            if (isset($params['callbackUrl'])) {
                $multipart[] = ['name' => 'callbackUrl', 'contents' => $params['callbackUrl']];
            }

            if (isset($params['coverPageId'])) {
                $multipart[] = ['name' => 'coverPageId', 'contents' => $params['coverPageId']];
            }

            if (isset($params['maxRetries'])) {
                $multipart[] = ['name' => 'maxRetries', 'contents' => (string)$params['maxRetries']];
            }

            // Log request details for debugging
            $url = "{$this->baseUrl}/v3/projects/{$this->projectId}/faxes";
            $apiKey = trim($this->config->getApiKey());
            $apiSecret = trim($this->config->getApiSecret());
            $maskedKey = substr($apiKey, 0, 4) . '...' . substr($apiKey, -4);
            $maskedSecret = substr($apiSecret, 0, 4) . '...' . substr($apiSecret, -4);
            $this->logger->debug("Sinch Fax API Request: POST {$url}");
            $this->logger->debug("Auth method: {$this->authMethod}");
            $this->logger->debug("API Key: {$maskedKey} (length: " . strlen($apiKey) . ")");
            $this->logger->debug("API Secret: {$maskedSecret} (length: " . strlen($apiSecret) . ")");

            // Show what the combined credentials look like before encoding
            $combined = "{$apiKey}:{$apiSecret}";
            $maskedCombined = substr($combined, 0, 10) . '...' . substr($combined, -10);
            $this->logger->debug("Combined credentials: {$maskedCombined}");

            $this->logger->debug("Request params: to={$params['to']}, files=" . count($params['files'] ?? []));

            $response = $this->httpClient->post(
                "/v3/projects/{$this->projectId}/faxes",
                [
                    'multipart' => $multipart,
                ]
            );

            $body = $response->getBody()->getContents();
            $this->logger->debug("Sinch Fax API Response: " . $body);
            return json_decode($body, true);
        } catch (GuzzleException $e) {
            // Log detailed error information
            $this->logger->error('Sinch Fax API error: ' . $e->getMessage());
            if (method_exists($e, 'getResponse') && $e->getResponse() !== null) {
                $responseBody = $e->getResponse()->getBody()->getContents();
                $this->logger->error('Sinch API Response Body: ' . $responseBody);
            }
            throw new \Exception('Failed to send fax: ' . $e->getMessage());
        }
    }

    /**
     * Get fax details
     *
     * @param string $faxId
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function getFax(string $faxId): array
    {
        try {
            $response = $this->httpClient->get(
                "/v3/projects/{$this->projectId}/faxes/{$faxId}"
            );

            $body = $response->getBody()->getContents();
            return json_decode($body, true);
        } catch (GuzzleException $e) {
            $this->logger->error('Sinch Fax API error: ' . $e->getMessage());
            throw new \Exception('Failed to get fax: ' . $e->getMessage());
        }
    }

    /**
     * List faxes
     *
     * @param array<string, mixed> $filters Optional filters
     * @return array<string, mixed>
     * @throws \Exception
     */
    public function listFaxes(array $filters = []): array
    {
        try {
            $queryParams = [];

            if (isset($filters['serviceId'])) {
                $queryParams['serviceId'] = $filters['serviceId'];
            }
            if (isset($filters['direction'])) {
                $queryParams['direction'] = $filters['direction'];
            }
            if (isset($filters['status'])) {
                $queryParams['status'] = $filters['status'];
            }
            if (isset($filters['to'])) {
                $queryParams['to'] = $filters['to'];
            }
            if (isset($filters['from'])) {
                $queryParams['from'] = $filters['from'];
            }
            if (isset($filters['createTime'])) {
                $queryParams['createTime'] = $filters['createTime'];
            }
            if (isset($filters['page'])) {
                $queryParams['page'] = $filters['page'];
            }
            if (isset($filters['pageSize'])) {
                $queryParams['pageSize'] = $filters['pageSize'];
            }

            $response = $this->httpClient->get(
                "/v3/projects/{$this->projectId}/faxes",
                ['query' => $queryParams]
            );

            $body = $response->getBody()->getContents();
            return json_decode($body, true);
        } catch (GuzzleException $e) {
            $this->logger->error('Sinch Fax API error: ' . $e->getMessage());
            throw new \Exception('Failed to list faxes: ' . $e->getMessage());
        }
    }

    /**
     * Download fax content
     *
     * @param string $faxId
     * @return string Binary content of the fax
     * @throws \Exception
     */
    public function downloadFax(string $faxId): string
    {
        try {
            $response = $this->httpClient->get(
                "/v3/projects/{$this->projectId}/faxes/{$faxId}/file"
            );

            return $response->getBody()->getContents();
        } catch (GuzzleException $e) {
            $this->logger->error('Sinch Fax API error: ' . $e->getMessage());
            throw new \Exception('Failed to download fax: ' . $e->getMessage());
        }
    }

    /**
     * Delete a fax
     *
     * @param string $faxId
     * @return bool
     * @throws \Exception
     */
    public function deleteFax(string $faxId): bool
    {
        try {
            $this->httpClient->delete(
                "/v3/projects/{$this->projectId}/faxes/{$faxId}"
            );
            return true;
        } catch (GuzzleException $e) {
            $this->logger->error('Sinch Fax API error: ' . $e->getMessage());
            throw new \Exception('Failed to delete fax: ' . $e->getMessage());
        }
    }
}
