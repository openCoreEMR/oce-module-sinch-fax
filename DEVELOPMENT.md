# OpenEMR Sinch Fax Module - Development Guide

## Overview

This module integrates Sinch Fax API with OpenEMR, providing secure fax sending and receiving capabilities with full HIPAA compliance features.

## Architecture

### Directory Structure

```
oce-module-sinch-fax/
├── src/
│   ├── Bootstrap.php              # Module initialization and event subscription
│   ├── GlobalConfig.php           # Configuration management
│   ├── Client/
│   │   └── SinchFaxClient.php    # Low-level Sinch API client
│   ├── Service/
│   │   └── FaxService.php        # Business logic for fax operations
│   └── Controller/
│       └── WebhookController.php  # Webhook endpoint handler
├── public/
│   ├── index.php                  # Main UI interface
│   └── webhook.php                # Webhook entry point
├── templates/                     # Twig templates (for future use)
├── table.sql                      # Database schema
├── cleanup.sql                    # Cleanup on uninstall
├── composer.json                  # Dependencies and metadata
├── openemr.bootstrap.php         # Module loader
└── version.php                    # Version information
```

### Key Components

#### 1. GlobalConfig (`src/GlobalConfig.php`)
- Manages all module configuration options
- Provides getters for API credentials, region, etc.
- Defines configuration schema for OpenEMR Globals interface

#### 2. SinchFaxClient (`src/Client/SinchFaxClient.php`)
- Direct interface to Sinch Fax API v3
- Handles authentication (Basic Auth and OAuth2)
- Methods:
  - `sendFax()` - Send fax with files or URLs
  - `getFax()` - Get fax details by ID
  - `listFaxes()` - List faxes with filters
  - `downloadFax()` - Download fax content
  - `deleteFax()` - Delete a fax

#### 3. FaxService (`src/Service/FaxService.php`)
- Business logic layer
- Database integration
- Methods:
  - `sendFax()` - Send and track faxes
  - `downloadAndSaveFax()` - Download and store fax files
  - `processIncomingFax()` - Handle incoming fax webhooks
  - `processFaxCompleted()` - Handle fax completion webhooks

#### 4. WebhookController (`src/Controller/WebhookController.php`)
- Handles incoming webhooks from Sinch
- Supports both multipart/form-data and application/json
- Processes INCOMING_FAX and FAX_COMPLETED events

#### 5. Bootstrap (`src/Bootstrap.php`)
- Initializes the module
- Registers global settings
- Adds menu items
- Subscribes to OpenEMR events

## Database Schema

### oce_sinch_faxes
Stores all fax records (sent and received):
- `sinch_fax_id` - Sinch API fax ID
- `direction` - INBOUND or OUTBOUND
- `from_number`, `to_number` - Phone numbers
- `status` - Fax status (QUEUED, IN_PROGRESS, COMPLETED, FAILED)
- `file_path` - Local storage path
- `patient_id` - Link to patient record
- `user_id` - User who sent the fax

### oce_sinch_services
Service configuration (for future multi-service support):
- `project_id`, `service_id` - Sinch identifiers
- `api_key`, `api_secret` - Encrypted credentials
- `region` - API region selection

### oce_sinch_cover_pages
Cover page templates:
- `name` - Cover page name
- `file_path` - PDF template path
- `sinch_cover_page_id` - ID from Sinch

## Installation

### Via Composer (Recommended)
```bash
composer require opencoreemr/oce-module-sinch-fax
```

### Manual Installation

1. Download the latest release
2. Extract to `openemr/interface/modules/custom_modules/oce-module-sinch-fax`
3. In OpenEMR: Administration > Modules > Manage Modules
4. Register, Install, Enable

### Manual Installation
1. Clone or extract to `openemr/interface/modules/custom_modules/oce-module-sinch-fax`
2. In OpenEMR: Administration > Modules > Manage Modules
3. Register, Install, Enable

## Configuration

1. Navigate to Administration > Globals > Sinch Fax
2. Set:
   - Enable Sinch Fax: Yes
   - Sinch Project ID: (from Sinch dashboard)
   - Sinch Service ID: (from Sinch dashboard)
   - Authentication Method: Basic or OAuth2
   - API Key/Secret: (your credentials)
   - API Region: Select region or use Global

## Usage

### Sending a Fax
```php
use OpenCoreEMR\Modules\SinchFax\Service\FaxService;

$faxService = new FaxService();
$result = $faxService->sendFax(
    '+15551234567',           // to
    ['/path/to/file.pdf'],    // files
    [
        'patient_id' => 123,
        'coverPageId' => 'my-cover-page'
    ]
);
```

### Webhook URL
The module automatically configures the webhook URL:
```
https://yourdomain.com/openemr/interface/modules/custom_modules/oce-module-sinch-fax/public/webhook.php
```

Configure this URL in your Sinch dashboard for:
- Incoming fax notifications
- Fax completed notifications

## Security Features

1. **Encrypted Credentials**: API keys/secrets stored encrypted in database
2. **CSRF Protection**: All forms use CSRF tokens
3. **File Permissions**: Fax files stored with 0660 permissions
4. **Webhook Validation**: (TODO: Add signature verification)
5. **Access Control**: OpenEMR ACL integration
6. **Audit Logging**: All operations logged via SystemLogger

## API Reference - Sinch Fax API v3

### Send Fax
```
POST /v3/projects/{projectId}/faxes
```
- Supports multipart/form-data or application/json
- Can send to multiple recipients (semicolon-separated)
- Supports files or contentUrl
- Optional cover pages with dynamic fields

### Get Fax
```
GET /v3/projects/{projectId}/faxes/{faxId}
```

### List Faxes
```
GET /v3/projects/{projectId}/faxes?direction=OUTBOUND&status=COMPLETED
```

### Download Fax
```
GET /v3/projects/{projectId}/faxes/{faxId}/file
```

## Webhooks

### INCOMING_FAX
Triggered when a fax is received on your Sinch number.

Payload (multipart/form-data):
- `event`: "INCOMING_FAX"
- `fax`: JSON object with fax details
- `file`: PDF attachment

### FAX_COMPLETED
Triggered when a sent fax completes (success or failure).

Payload:
- `event`: "FAX_COMPLETED"
- `fax`: JSON object with status, error codes, etc.

## Testing

### Test Fax Number
To test without charges, send fax to: `+19898989898`

This emulates all aspects of a real fax without billing.

## Future Enhancements

1. Cover page designer UI
2. Fax queue management
3. Retry logic for failed faxes
4. Document viewer integration
5. Enhanced patient chart integration
6. Fax templates
7. Scheduled fax sending
8. Fax broadcast (bulk sending)
9. Advanced reporting and analytics

## Troubleshooting

### Common Issues

1. **Webhooks not received**: Check firewall, ensure URL is publicly accessible
2. **Authentication failures**: Verify API key/secret, check region setting
3. **File upload errors**: Check file permissions, PHP upload limits
4. **Database errors**: Run table.sql manually if auto-install fails

### Logs
Check OpenEMR logs for detailed error messages:
- `sites/default/documents/logs_and_misc/log`

## Contributing

### Development Setup

1. Fork the repository
2. Clone your fork
3. Install dependencies:
   ```bash
   composer install
   ```
4. Install pre-commit hooks:
   ```bash
   pip install pre-commit
   pre-commit install
   ```

### Code Quality

This project uses pre-commit hooks to enforce code quality standards. The hooks will automatically run on `git commit`:

- **PHP Syntax Check** - Validates PHP syntax
- **PHP CodeSniffer** - Enforces PSR-12 coding standards
- **PHPStan** - Static analysis (level 8)
- **Rector** - PHP 8.2+ compatibility checks

To run checks manually:
```bash
pre-commit run --all-files
```

To skip hooks (not recommended):
```bash
git commit --no-verify
```

### Making Changes

1. Create a feature branch
2. Make your changes
3. The pre-commit hooks will run automatically
4. Fix any issues reported by the hooks
5. Submit a pull request

## License

GNU General Public License v3.0 or later

## Support

- Email: support@opencoreemr.com
- Issues: https://github.com/opencoreemr/oce-module-sinch-fax/issues
