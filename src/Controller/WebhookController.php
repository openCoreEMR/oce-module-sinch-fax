<?php

/**
 * Webhook Controller - handles incoming webhooks from Sinch
 *
 * @package   OpenCoreEMR
 * @link      http://www.open-emr.org
 * @author    Michael A. Smith <michael@opencoreemr.com>
 * @copyright Copyright (c) 2025 OpenCoreEMR Inc
 * @license   GNU General Public License 3
 */

namespace OpenCoreEMR\Modules\SinchFax\Controller;

use OpenCoreEMR\Modules\SinchFax\GlobalsAccessor;
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;
use OpenCoreEMR\Modules\SinchFax\GlobalConfig;
use OpenEMR\Common\Logging\SystemLogger;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class WebhookController
{
    private readonly FaxService $faxService;
    private readonly GlobalConfig $config;
    private readonly SystemLogger $logger;

    public function __construct(?GlobalsAccessor $globalsAccessor = null)
    {
        $globalsAccessor = $globalsAccessor ?? new GlobalsAccessor();
        $this->config = new GlobalConfig($globalsAccessor);
        $this->faxService = new FaxService($this->config);
        $this->logger = new SystemLogger();
    }

    /**
     * Handle incoming webhook
     */
    public function handleWebhook(): Response
    {
        $request = Request::createFromGlobals();

        $this->logger->info("Webhook received", [
            'method' => $request->getMethod(),
            'content_type' => $request->headers->get('Content-Type', '')
        ]);

        if (!$request->isMethod('POST')) {
            return new JsonResponse(['error' => 'Method not allowed'], Response::HTTP_METHOD_NOT_ALLOWED);
        }

        $contentType = $request->headers->get('Content-Type', '');

        if (str_contains((string) $contentType, 'multipart/form-data')) {
            $data = $this->parseMultipartFormData($request);
        } elseif (str_contains((string) $contentType, 'application/json')) {
            $data = $request->toArray();
        } else {
            return new JsonResponse(['error' => 'Unsupported content type'], Response::HTTP_BAD_REQUEST);
        }

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid request data'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $event = $data['event'] ?? '';

            match ($event) {
                'INCOMING_FAX' => $this->faxService->processIncomingFax($data),
                'FAX_COMPLETED' => $this->faxService->processFaxCompleted($data),
                default => $this->logger->warning("Unknown webhook event: {$event}"),
            };

            return new JsonResponse(['status' => 'success'], Response::HTTP_OK);
        } catch (\Throwable $e) {
            $this->logger->error("Webhook processing error: " . $e->getMessage());
            return new JsonResponse(['error' => 'Internal server error'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function parseMultipartFormData(Request $request): array
    {
        $data = [];

        $data['event'] = $request->request->get('event', '');
        $data['eventTime'] = $request->request->get('eventTime', '');

        $faxJson = $request->request->get('fax');
        if ($faxJson !== null) {
            $data['fax'] = json_decode((string) $faxJson, true);
        }

        $file = $request->files->get('file');
        if ($file && $file->isValid()) {
            $data['file'] = base64_encode(file_get_contents($file->getPathname()));
            $data['fileType'] = 'PDF';
        }

        return $data;
    }
}
