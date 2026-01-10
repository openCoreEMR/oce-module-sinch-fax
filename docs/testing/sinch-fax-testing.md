# Sinch Fax Module Testing Guide

This document describes end-to-end testing workflows for the Sinch Fax module.

## Prerequisites

1. **Docker environment running**
   ```bash
   task dev:start
   task dev:port  # Note the port number
   ```

2. **Module installed and enabled**
   - Navigate to Admin > Modules > Manage Modules
   - Register, Install, and Enable "oce-module-sinch-fax"

3. **Module configured**
   - Navigate to Admin > Config > OpenCoreEMR Sinch Fax Module
   - Configure required settings (see Configuration section below)

## Configuration Settings

### Required Settings

| Setting | Description |
|---------|-------------|
| Enable Sinch Fax | Must be checked |
| Sinch Project ID | From your Sinch dashboard |
| Authentication Method | `basic` or `oauth` |
| API Key | Your Sinch API key (for basic auth) |
| API Secret | Your Sinch API secret (for basic auth) |
| API Region | `global`, `us`, or `eu` |

### Optional Settings

| Setting | Description |
|---------|-------------|
| Sinch Service ID | Not required for fax functionality |
| File Storage Path | Custom storage location (uses default if empty) |
| Default Retry Count | Number of retry attempts (default: 3) |

### Webhook Settings (Required for Inbound Faxes)

| Setting | Description |
|---------|-------------|
| Webhook Username | HTTP Basic Auth username |
| Webhook Password | HTTP Basic Auth password |
| Webhook IP Whitelist | Allowed source IPs (optional) |

## Testing Workflows

### 1. Unit Tests

Run the PHPUnit test suite:

```bash
# Run all tests
task test

# Run with coverage
task test:coverage

# Run in Docker
task test:docker
```

### 2. Sending a Fax (Outbound)

#### Via OpenEMR UI

1. Login to OpenEMR (admin/pass)
2. Navigate to Modules > OpenCoreEMR Sinch Fax
3. Select "Send Fax" or equivalent option
4. Fill in recipient number and attach document
5. Submit and note the fax ID
6. Check status in fax list

#### Via Test Script (Development)

```bash
# This requires implementing a send-fax test script
# Currently only webhook testing is available
```

### 3. Receiving a Fax (Inbound)

#### Real Fax Testing

1. Start webhook tunnel:
   ```bash
   task webhook:tunnel
   ```

2. Configure webhook URL in Sinch dashboard:
   ```
   https://<your-tailscale-hostname>/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php
   ```

3. Configure HTTP Basic Auth credentials in Sinch

4. Send a fax to your Sinch number from another fax machine/service

5. Verify receipt:
   - Check webhook logs: `task dev:logs`
   - Check database: `task module:data`
   - View in OpenEMR UI

#### Simulated Testing

```bash
# Send simulated incoming fax
task webhook:test:incoming

# With custom options
task webhook:test -- incoming --fax-id=test-001 --pages=5 --from=+15551234567

# With PDF attachment
task webhook:test -- incoming --with-file=./test-document.pdf
```

### 4. Status Updates

#### FAX_COMPLETED Webhook

```bash
# Test successful completion
task webhook:test:completed

# Test failed fax
task webhook:test:failed
```

#### Reconciliation

On each fax list page load, the module queries the Sinch API to detect any faxes that may have been missed due to webhook delivery failures. Missed faxes appear with an error message indicating the document was not received.

### 5. Database Verification

```bash
# List module tables
task module:tables

# Check data counts
task module:data

# Query specific data
task db:query -- "SELECT * FROM oce_sinch_faxes ORDER BY created_at DESC LIMIT 10"
```

## Test Scenarios

### Scenario 1: Basic Outbound Fax

1. [ ] Send fax via UI
2. [ ] Verify fax record created
3. [ ] Receive FAX_COMPLETED webhook
4. [ ] Verify status updated to COMPLETED
5. [ ] Check fax appears in history

### Scenario 2: Failed Outbound Fax

1. [ ] Send fax to invalid number
2. [ ] Receive FAX_COMPLETED with FAILURE status
3. [ ] Verify error recorded
4. [ ] Check retry behavior (if configured)

### Scenario 3: Inbound Fax (HIPAA Mode)

1. [ ] Receive INCOMING_FAX webhook with file attachment
2. [ ] Verify document extracted from webhook
3. [ ] Verify document stored locally
4. [ ] Check fax record created with correct metadata
5. [ ] Verify document accessible in OpenEMR

### Scenario 4: Authentication Failures

1. [ ] Send webhook with wrong Basic Auth credentials
2. [ ] Verify 401 response returned
3. [ ] No database record created

### Scenario 5: IP Whitelist (if configured)

1. [ ] Send webhook from allowed IP
2. [ ] Verify processing succeeds
3. [ ] Send webhook from disallowed IP
4. [ ] Verify 403 response returned

## Troubleshooting

### Module Not Appearing in Config

1. Verify module is registered in Manage Modules
2. Check module is installed (not just registered)
3. Check module is enabled
4. Log out and log back in

### Webhooks Not Processing

1. Check webhook endpoint is accessible
2. Verify authentication credentials
3. Check OpenEMR error logs
4. Verify module is enabled

### Fax Documents Not Saved

1. Check file storage path permissions
2. Verify webhook contains file data (HIPAA mode)
3. Check for PHP errors in logs

### Database Connection Issues

1. Verify Docker MySQL is running: `task dev:status`
2. Check database credentials
3. Run `task module:tables` to verify tables exist

## Code Quality

Before committing changes:

```bash
# Run all checks
task check

# Individual checks
task check:phpcs    # Code style
task check:phpstan  # Static analysis
task check:fix      # Auto-fix issues
```

## Useful Commands Reference

```bash
# Development
task dev:start          # Start Docker
task dev:stop           # Stop Docker
task dev:logs           # View logs
task dev:shell          # Container shell

# Module
task module:tables      # List tables
task module:data        # Show data counts
task module:cleanup     # Drop tables (careful!)

# Testing
task test               # Run tests
task test:coverage      # With coverage
task webhook:test:incoming   # Test webhook
task webhook:tunnel     # Expose webhook

# Database
task db:shell           # MySQL shell
task db:query -- "SQL"  # Run query
```
