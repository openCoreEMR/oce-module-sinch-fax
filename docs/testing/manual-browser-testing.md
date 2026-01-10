# Manual Browser Testing Guide

This document describes how to manually test the Sinch Fax module using browser automation or direct browser interaction with the Sinch dashboard and OpenEMR.

## Prerequisites

1. **Docker environment running**
   ```bash
   task dev:start
   task dev:port  # Note the port number (e.g., 53527)
   ```

2. **Module installed and configured**
   - Navigate to Admin > Modules > Manage Modules
   - Register, Install, and Enable "oce-module-sinch-fax"
   - Configure settings in Admin > Config > OpenCoreEMR Sinch Fax Module

3. **Tailscale installed** (for webhook testing)

## Sinch Dashboard URLs

| Page | URL |
|------|-----|
| Services list | https://dashboard.sinch.com/fax/services |
| Edit service | https://dashboard.sinch.com/fax/services/edit/{service_id} |
| All faxes | https://dashboard.sinch.com/fax/faxes |
| Try it out (send test) | https://dashboard.sinch.com/fax/try-it-out |

## Test Workflow

### Phase 1: Setup Tailscale Funnel

Tailscale Funnel exposes your local OpenEMR instance to the internet, allowing Sinch to send webhooks.

```bash
# Start the funnel (background mode on port 10000)
task webhook:tunnel

# This outputs your public URL, e.g.:
# https://michaels-macbook-pro.tail16dfaa.ts.net:10000/

# Check funnel status
task webhook:tunnel:status

# Stop the funnel when done
task webhook:tunnel:off
```

### Phase 2: Configure Webhook in Sinch Dashboard

1. Navigate to https://dashboard.sinch.com/fax/services
2. Click on your service name (e.g., "Default Service")
3. Note the **Service ID** displayed under the service name heading
4. In the "INCOMING" tab, configure:
   - **Incoming webhook URL**: Your Tailscale URL with Basic Auth credentials embedded:
     ```
     https://USERNAME:PASSWORD@your-hostname.ts.net:10000/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php
     ```
   - **Webhook content type**: `application/json` (recommended)
5. Click **Save**

**Important Notes:**
- Basic Auth credentials must match the Webhook Username/Password in OpenEMR config
- Use simple passwords without special characters like `!` which may cause URL encoding issues
- The Service ID shown on this page should match what you see in OpenEMR (or leave OpenEMR's Service ID blank to use the project default)

### Phase 3: Verify OpenEMR Configuration

1. Login to OpenEMR at your Tailscale URL (e.g., https://your-hostname.ts.net:10000)
   - Default credentials: admin / pass
2. Navigate to Admin > Config > OpenCoreEMR Sinch Fax Module
3. Verify settings:
   - **Enable Sinch Fax**: Checked
   - **Sinch Project ID**: Your project ID
   - **Sinch Service ID**: Blank (uses default) or specific service ID
   - **Authentication Method**: basic
   - **API Key/Secret**: Configured
   - **API Region**: global (or your region)
   - **Webhook Username/Password**: Match Sinch configuration

### Phase 4: Create Test Patient

```bash
# Generate a synthetic patient using Synthea
openemr-cmd irp 1

# This creates a patient you can use for attaching faxes
```

### Phase 5: Test Inbound Fax (Webhook)

1. In Sinch dashboard, go to Services > Edit your service
2. Click **"Send test request"** button next to the webhook URL field
3. Check OpenEMR logs for webhook receipt:
   ```bash
   task dev:logs | grep -i "webhook\|sinch\|fax"
   ```
4. Look for a 200 response from the webhook endpoint
5. In OpenEMR, navigate to Modules > OpenCoreEMR Sinch Fax
6. Verify the test fax appears in the "Recent Faxes" list

### Phase 6: Attach Fax to Patient

1. In the fax list, find your test fax
2. Click **"Move to Patient"** button
3. Enter the Patient ID (e.g., `1` for the first patient)
4. Click **"Move to Patient"** to confirm
5. Verify success message shows Document ID
6. The fax row now shows "Moved to Patient X" instead of action buttons

### Phase 7: Test Outbound Fax

Send a fax from OpenEMR to verify outbound functionality and FAX_COMPLETED webhook handling.

1. Ensure Tailscale funnel is running (for FAX_COMPLETED webhook)
2. In OpenEMR, go to Modules > OpenCoreEMR Sinch Fax > Send Fax
3. Enter destination number: **+19898989898** (Sinch test number)
4. Attach a PDF document or enter content
5. Click Send
6. Monitor logs for FAX_COMPLETED webhook:
   ```bash
   task dev:logs | grep -i "fax_completed\|sinch"
   ```
7. Verify fax status updates in the fax list

### Phase 8: Verify HIPAA Mode

**Note:** HIPAA mode verification requires real faxes (not test webhooks).

After sending an outbound fax:
1. Navigate to https://dashboard.sinch.com/fax/faxes
2. Find your sent fax in the list
3. In HIPAA mode:
   - Fax metadata (from, to, pages, status) is visible
   - Fax document content is NOT visible/downloadable
   - Documents are only delivered via webhook, not stored on Sinch servers

### Phase 9: Test Reconciliation (Optional)

Reconciliation detects faxes that were received by Sinch but not delivered via webhook (e.g., due to endpoint being unavailable).

**To test:**
1. Disable the Tailscale funnel: `task webhook:tunnel:off`
2. Send a real fax to your Sinch fax number (requires another fax service)
3. The webhook will fail to deliver
4. Re-enable funnel (optional): `task webhook:tunnel`
5. In OpenEMR, navigate to Modules > OpenCoreEMR Sinch Fax
6. The reconciliation runs on page load and should detect the missed fax
7. Missed faxes appear with error: "Fax acknowledged, but document was not received by OpenEMR. Contact sender to re-send."

**Note:** In HIPAA mode, reconciled faxes cannot retrieve document content - only metadata is available.

## Troubleshooting

### Webhook Returns 500 Error

Check OpenEMR logs for the specific error:
```bash
docker compose logs --tail=100 openemr | grep -i error
```

Common causes:
- Missing database columns (run table migrations)
- Invalid JSON payload
- Authentication failure

### Webhook Returns 401 Error

- Verify Basic Auth credentials in webhook URL match OpenEMR config
- Check for special characters in password that may need URL encoding

### Webhook Not Received

1. Verify Tailscale funnel is running: `task webhook:tunnel:status`
2. Test the URL is accessible from the internet
3. Check Sinch dashboard for webhook delivery errors

### Fax Not Appearing in List

1. Check database directly:
   ```bash
   task db:query -- "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 5"
   ```
2. Verify webhook processing completed without errors
3. Check module is enabled in OpenEMR

### "Move to Patient" Fails

1. Verify patient ID exists
2. Check for JavaScript errors in browser console
3. Verify file storage path is writable

## Database Verification Commands

```bash
# List module tables
task module:tables

# Check fax counts
task module:data

# Query recent faxes
task db:query -- "SELECT id, sinch_fax_id, direction, status, read_status, patient_id FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 10"

# Check reconciliation timestamp
task db:query -- "SELECT * FROM oce_sinch_reconciliation"
```

## Test Scenarios Checklist

### Basic Inbound Fax
- [ ] Tailscale funnel running
- [ ] Webhook URL configured in Sinch with Basic Auth
- [ ] Send test request from Sinch service edit page
- [ ] Webhook returns 200 response
- [ ] Fax appears in OpenEMR fax list
- [ ] Fax can be viewed (if document attached)
- [ ] Fax can be moved to patient chart

### Outbound Fax
- [ ] Send fax from OpenEMR Send Fax tab
- [ ] Use test number +19898989898
- [ ] FAX_COMPLETED webhook received
- [ ] Fax status updates in list
- [ ] Fax visible in Sinch dashboard (metadata only in HIPAA mode)

### Authentication
- [ ] Invalid credentials return 401
- [ ] Valid credentials return 200

### HIPAA Mode (requires real faxes)
- [ ] Fax metadata visible in Sinch dashboard
- [ ] Fax document content NOT visible/downloadable in Sinch dashboard
- [ ] Documents delivered only via webhook payload

### Reconciliation
- [ ] Missed faxes detected on page load
- [ ] Error message indicates document unavailable
- [ ] Metadata (sender, recipient, pages) available

## Known Issues

### Reconciliation API Error (422)

**Bug Location:** `src/Service/FaxService.php:297`

**Symptom:** The reconciliation feature fails with a 422 Unprocessable Entity error from the Sinch API.

**Error URL:**
```
GET https://fax.api.sinch.com/v3/projects/{project}/faxes?direction=INBOUND&createTime=%3E%3D2026-01-10T03%3A03%3A17%2B00%3A00
```

**Root Cause:**

The code builds the filter incorrectly:
```php
$filters['createTime'] = '>=' . $lastSyncTime->format('c');
```

This produces a query parameter like `createTime=>=2026-01-10T03:03:17+00:00`, but the Sinch API expects the comparison operator as part of the parameter **name**, not the value:
- **Wrong:** `?createTime=>=2026-01-10T03:03:17+00:00`
- **Correct:** `?createTime>=2026-01-10T03:03:17Z`

Per [Sinch API documentation](https://developers.sinch.com/docs/fax/api-reference/fax/tag/Faxes/#tag/Faxes/operation/ListFaxes), range queries should use:
```
?createTime>=2021-10-01&createTime<=2021-10-30
```

**Proposed Fix:**

The `SinchFaxClient::listFaxes()` method needs to handle comparison operators specially, building the query parameter name with the operator instead of including it in the value.

**Workaround:**

Until fixed, reconciliation will fail on subsequent page loads after the first sync. The first sync (when `last_sync_time` is NULL) works because no `createTime` filter is applied.

**To Check Logs:**
```bash
docker compose logs --tail=100 openemr | grep -i "reconcil\|422"
```
