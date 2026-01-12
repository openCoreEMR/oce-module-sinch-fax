<?php

/**
 * Webhook Controller - handles incoming webhooks from Sinch
 *
 * @package   OpenCoreEMR
 * @link      https://opencoreemr.com
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\Exception\FaxValidationException;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
    private readonly SystemLogger $logger;

    public function __construct(
        private readonly FaxService $faxService
    ) {
        $this->logger = new SystemLogger();
    }

    /**
     * Dispatch incoming webhook request
     */
    public function dispatch(): Response
    {
        $request = Request::createFromGlobals();

        // Only accept POST requests
        if (!$request->isMethod('POST')) {
            $this->logger->warning("Webhook received non-POST request: " . $request->getMethod());
            return new JsonResponse(
                ['error' => 'Method not allowed'],
                Response::HTTP_METHOD_NOT_ALLOWED
            );
        }

        // Log incoming webhook for HIPAA audit trail
        $this->logger->info("Webhook received from: " . $request->getClientIp());

        // Parse the webhook payload
        $payload = $this->parsePayload($request);

        if ($payload === []) {
            $this->logger->error("Webhook received empty or invalid payload");
            return new JsonResponse(
                ['error' => 'Invalid payload'],
                Response::HTTP_BAD_REQUEST
            );
        }

        // Get event type
        $eventTypeRaw = $payload['event'] ?? null;
        if (!is_string($eventTypeRaw) || $eventTypeRaw === '') {
            $this->logger->error("Webhook missing event type");
            return new JsonResponse(
                ['error' => 'Missing event type'],
                Response::HTTP_BAD_REQUEST
            );
        }
        $eventType = $eventTypeRaw;

        $this->logger->info("Processing webhook event: {$eventType}");

        // Route to appropriate handler
        return match ($eventType) {
            'INCOMING_FAX' => $this->handleIncomingFax($request, $payload),
            'FAX_COMPLETED' => $this->handleFaxCompleted($payload),
            default => $this->handleUnknownEvent($eventType),
        };
    }

    /**
     * Parse webhook payload from request
     *
     * Sinch can send webhooks as:
     * - multipart/form-data: with 'fax' as JSON and 'file' as PDF attachment
     * - application/json: with fax data and base64-encoded file
     *
     * @return array<string, mixed>
     */
    private function parsePayload(Request $request): array
    {
        $contentType = $request->headers->get('Content-Type', '');

        // Handle multipart/form-data
        if (str_contains((string) $contentType, 'multipart/form-data')) {
            $event = $request->request->get('event');
            $faxJson = $request->request->get('fax');
            $faxData = is_string($faxJson) ? json_decode($faxJson, true) : [];

            return [
                'event' => $event,
                'fax' => is_array($faxData) ? $faxData : [],
                'hasFile' => $request->files->has('file'),
            ];
        }

        // Handle application/json
        if (str_contains((string) $contentType, 'application/json')) {
            /** @var string $content */
            $content = $request->getContent();
            $data = json_decode($content, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->logger->error("Webhook JSON parse error: " . json_last_error_msg());
                return [];
            }

            return is_array($data) ? $data : [];
        }

        // Try to parse as form data
        $event = $request->request->get('event');
        if ($event) {
            $faxJson = $request->request->get('fax');
            $faxData = is_string($faxJson) ? json_decode($faxJson, true) : [];

            return [
                'event' => $event,
                'fax' => is_array($faxData) ? $faxData : [],
                'hasFile' => $request->files->has('file'),
            ];
        }

        return [];
    }

    /**
     * Handle INCOMING_FAX event
     *
     * @param array<string, mixed> $payload
     */
    private function handleIncomingFax(Request $request, array $payload): Response
    {
        /** @var array<string, mixed> $faxData */
        $faxData = is_array($payload['fax'] ?? null) ? $payload['fax'] : [];
        $faxIdRaw = $faxData['id'] ?? null;
        $faxId = is_scalar($faxIdRaw) ? (string)$faxIdRaw : 'unknown';

        $this->logger->info("Processing incoming fax: {$faxId}");

        try {
            // Get file content if present in the webhook
            $fileContent = null;
            if ($request->files->has('file')) {
                $uploadedFile = $request->files->get('file');
                if (
                    $uploadedFile instanceof \Symfony\Component\HttpFoundation\File\UploadedFile
                    && $uploadedFile->isValid()
                ) {
                    $content = file_get_contents($uploadedFile->getPathname());
                    $fileContent = $content !== false ? $content : null;
                }
            } elseif (isset($payload['fileBase64'])) {
                $fileBase64 = $payload['fileBase64'];
                // base64_decode with strict=false always returns string (empty string for invalid input)
                $fileContent = is_string($fileBase64) ? (base64_decode($fileBase64) ?: null) : null;
            }

            // Process the incoming fax
            $this->faxService->processIncomingFax($faxData, $fileContent);

            $this->logger->info("Successfully processed incoming fax: {$faxId}");

            return new JsonResponse(
                ['status' => 'success', 'faxId' => $faxId],
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $this->logger->error("Failed to process incoming fax {$faxId}: " . $e->getMessage());

            return new JsonResponse(
                ['error' => 'Failed to process fax', 'faxId' => $faxId],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Handle FAX_COMPLETED event
     *
     * @param array<string, mixed> $payload
     */
    private function handleFaxCompleted(array $payload): Response
    {
        /** @var array<string, mixed> $faxData */
        $faxData = is_array($payload['fax'] ?? null) ? $payload['fax'] : [];
        $faxIdRaw = $faxData['id'] ?? null;
        $faxId = is_scalar($faxIdRaw) ? (string)$faxIdRaw : 'unknown';

        $this->logger->info("Processing fax completed event: {$faxId}");

        try {
            $this->faxService->processFaxCompleted($faxData);

            $this->logger->info("Successfully processed fax completed: {$faxId}");

            return new JsonResponse(
                ['status' => 'success', 'faxId' => $faxId],
                Response::HTTP_OK
            );
        } catch (\Throwable $e) {
            $this->logger->error("Failed to process fax completed {$faxId}: " . $e->getMessage());

            return new JsonResponse(
                ['error' => 'Failed to process fax status', 'faxId' => $faxId],
                Response::HTTP_INTERNAL_SERVER_ERROR
            );
        }
    }

    /**
     * Handle unknown event types
     */
    private function handleUnknownEvent(string $eventType): Response
    {
        $this->logger->warning("Received unknown webhook event type: {$eventType}");

        return new JsonResponse(
            ['status' => 'ignored', 'message' => "Unknown event type: {$eventType}"],
            Response::HTTP_OK
        );
    }
}
