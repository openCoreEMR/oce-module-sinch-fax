# Webhook Testing Guide

This document describes how to test Sinch Fax webhooks locally during development.

## Overview

Sinch sends webhooks to notify of fax events:
- **INCOMING_FAX** - When a fax is received
- **FAX_COMPLETED** - When an outbound fax completes (success or failure)

**CRITICAL (HIPAA Mode):** In HIPAA mode, fax documents are ONLY available during webhook delivery. They cannot be retrieved later via API. This makes webhook handling essential for inbound faxes.

## Reconciliation

If webhooks fail to deliver (network issues, server downtime, etc.), the module includes a reconciliation mechanism:

- On each fax list page load, the module queries Sinch for recent inbound faxes
- Any faxes not already in the local database are created with an error message
- These "reconciled" faxes appear with: *"Fax acknowledged, but document was not received by OpenEMR. Contact sender to re-send."*
- The last sync time is tracked to avoid re-processing

**Note:** In HIPAA mode, reconciled faxes cannot retrieve the document content—only metadata is available. The sender must re-send the fax.

## Webhook Authentication

The Sinch Fax webhook endpoint requires:

1. **HTTP Basic Authentication** - Username and password configured in module settings
2. **IP Whitelist** - Optional list of allowed Sinch IP addresses

### Configuration Required

These settings need to be configured in Admin > Config > OpenCoreEMR Sinch Fax Module:

| Setting | Description |
|---------|-------------|
| Webhook Username | Username for HTTP Basic Auth |
| Webhook Password | Password for HTTP Basic Auth |
| Webhook IP Whitelist | Comma-separated list of allowed IPs (optional) |

**Note:** If these settings don't exist yet, they need to be implemented in the module.

## Local Testing Methods

### Method 1: Tailscale Funnel (Recommended for Real Webhooks)

Tailscale Funnel exposes your local development environment to the internet securely.

```bash
# Start Docker environment
task dev:start

# Start Tailscale Funnel
task webhook:tunnel

# This shows your public webhook URL:
# https://<your-tailscale-hostname>/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php
```

Configure this URL in your Sinch dashboard as the webhook callback URL.

#### Tailscale Commands

```bash
# Check tunnel status
task webhook:tunnel:status

# Stop tunnel
task webhook:tunnel:off
```

### Method 2: Simulated Payloads (For Development)

Use the test script to send simulated webhook payloads without real Sinch integration:

```bash
# Send INCOMING_FAX event
task webhook:test:incoming

# Send FAX_COMPLETED (success)
task webhook:test:completed

# Send FAX_COMPLETED (failure)
task webhook:test:failed

# Custom options
task webhook:test -- incoming --fax-id=test-123 --pages=3

# With a test PDF file
task webhook:test -- incoming --with-file=/path/to/test.pdf
```

### Test Script Options

| Option | Description | Default |
|--------|-------------|---------|
| `--fax-id=ID` | Custom fax identifier | Auto-generated |
| `--from=NUMBER` | Sender phone number | +15551234567 |
| `--to=NUMBER` | Recipient phone number | +15559876543 |
| `--pages=N` | Number of pages | 2 |
| `--with-file=PATH` | Include PDF attachment | None |

## Webhook Payload Format

Sinch sends webhooks as `multipart/form-data` POST requests.

### INCOMING_FAX Event

```json
{
  "event": "INCOMING_FAX",
  "fax": {
    "id": "fax-uuid-here",
    "projectId": "project-uuid",
    "serviceId": "service-uuid",
    "direction": "INBOUND",
    "from": "+15551234567",
    "to": "+15559876543",
    "status": "COMPLETED",
    "numberOfPages": 2,
    "contentUrl": "https://fax.sinch.com/v3/projects/.../file",
    "createTime": "2026-01-09T12:00:00Z",
    "completedTime": "2026-01-09T12:01:00Z"
  }
}
```

**Note:** In HIPAA mode, the `file` field contains the actual PDF content in the multipart request. The `contentUrl` may not be accessible after webhook delivery.

### FAX_COMPLETED Event

```json
{
  "event": "FAX_COMPLETED",
  "fax": {
    "id": "fax-uuid-here",
    "projectId": "project-uuid",
    "direction": "OUTBOUND",
    "from": "+15559876543",
    "to": "+15551234567",
    "status": "COMPLETED",
    "numberOfPages": 3,
    "createTime": "2026-01-09T12:00:00Z",
    "completedTime": "2026-01-09T12:02:00Z"
  }
}
```

For failed faxes:
```json
{
  "status": "FAILURE",
  "errorCode": "NO_ANSWER",
  "errorMessage": "Remote fax machine did not answer"
}
```

## Webhook Endpoint

**URL:** `/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php`

**Requirements:**
- POST method only
- Content-Type: `multipart/form-data` (default) or `application/json`
- HTTP Basic Authentication header
- Source IP must be in whitelist (if configured)

**Does NOT require OpenEMR session authentication** - the endpoint is accessed directly by Sinch servers.

## Testing Checklist

### For Simulated Testing

- [ ] Docker environment running (`task dev:start`)
- [ ] Module installed and enabled in OpenEMR
- [ ] Run `task webhook:test:incoming` and verify response
- [ ] Check OpenEMR logs for processing: `task dev:logs`
- [ ] Verify fax record created in database: `task module:data`

### For Real Sinch Testing

- [ ] Tailscale Funnel running (`task webhook:tunnel`)
- [ ] Webhook URL configured in Sinch dashboard
- [ ] HTTP Basic Auth credentials configured in Sinch
- [ ] Send a test fax to your Sinch number
- [ ] Verify webhook received in logs
- [ ] Verify fax document stored correctly

## Troubleshooting

### Webhook Not Received

1. Check Tailscale Funnel is running: `task webhook:tunnel:status`
2. Verify URL is correct in Sinch dashboard
3. Check OpenEMR error logs: `task dev:logs:errors`

### Authentication Failed

1. Verify Basic Auth credentials match Sinch configuration
2. Check IP whitelist if configured
3. Look for 401/403 responses in logs

### Fax Document Not Saved

1. Check file storage path is configured and writable
2. Verify HIPAA mode handling is implemented
3. Check for errors during webhook processing

### Database Errors

1. Check module tables exist: `task module:tables`
2. Verify database connection in logs
3. Check for SQL errors in OpenEMR logs
