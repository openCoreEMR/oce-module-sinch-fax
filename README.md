# OpenEMR Sinch Fax Module

A secure fax integration module for OpenEMR using the Sinch Fax API.

## Features

- **Send Faxes**: Send faxes to one or multiple recipients
- **Multiple File Formats**: Support for PDF, TIFF, PNG, JPEG, DOC, and DOCX
- **Security**: Encrypted storage of API credentials, secure file handling
- **Patient Integration**: Link faxes to patient records
- **Audit Trail**: Complete tracking of all fax activity

## Requirements

- OpenEMR 7.0.0 or later
- PHP 8.2 or later
- MySQL 5.7 or later / MariaDB 10.2 or later
- Sinch Fax account with API credentials

## Installation

### Via Composer (Recommended)

1. Navigate to your OpenEMR installation directory
2. Install the module via Composer:
   ```bash
   composer require opencoreemr/oce-module-sinch-fax
   ```

3. Log into OpenEMR as an administrator
4. Navigate to **Administration > Modules > Manage Modules**
5. Find "OpenCoreEMR Sinch Fax" in the list and click **Register**
6. Click **Install**
7. Click **Enable**

### Manual Installation

1. Download the latest release
2. Extract to `interface/modules/custom_modules/oce-module-sinch-fax` (relative to your OpenEMR root directory)
3. Follow steps 3-7 from the Composer installation

## Configuration

The module supports three configuration modes with the following precedence (highest to lowest):

1. **Environment variables** — each `OCE_SINCH_FAX_*` variable overrides the corresponding setting
2. **YAML files** — for Kubernetes-style deployments with ConfigMap/Secret volumes
3. **Database (UI)** — the default; edit settings in **Administration > Globals > OpenCoreEMR Sinch Fax Module**

When file-based or environment configuration is active, the admin UI displays "Configuration Managed Externally" instead of editable fields.

### Configuration Settings

#### General Settings (not sensitive)

| Setting | Env Var | Default | Description |
|---------|---------|---------|-------------|
| Enabled | `OCE_SINCH_FAX_ENABLED` | `false` | Enable the module |
| Project ID | `OCE_SINCH_FAX_PROJECT_ID` | | Sinch project ID (required) |
| Service ID | `OCE_SINCH_FAX_SERVICE_ID` | | Sinch service ID (optional, not required for fax) |
| Auth Method | `OCE_SINCH_FAX_AUTH_METHOD` | `basic` | `basic` or `oauth` |
| Region | `OCE_SINCH_FAX_REGION` | `global` | API region (`global`, `use1`, `eu1`, `sae1`, `apse1`, `apse2`) |
| File Storage Path | `OCE_SINCH_FAX_FILE_STORAGE_PATH` | | Fax file storage directory (defaults to site documents) |
| Default Retry Count | `OCE_SINCH_FAX_DEFAULT_RETRY_COUNT` | `3` | Number of send retries |
| Webhook Username | `OCE_SINCH_FAX_WEBHOOK_USERNAME` | | HTTP Basic Auth username for incoming webhooks |
| Webhook IP Allowlist | `OCE_SINCH_FAX_WEBHOOK_IP_ALLOWLIST` | | Allowed IPs/CIDRs for webhooks (comma or newline-separated; empty allows all) |

#### Secrets (treat as sensitive)

| Setting | Env Var | Description |
|---------|---------|-------------|
| API Key | `OCE_SINCH_FAX_API_KEY` | Sinch API key (for Basic Auth) |
| API Secret | `OCE_SINCH_FAX_API_SECRET` | Sinch API secret (for Basic Auth) |
| OAuth Token | `OCE_SINCH_FAX_OAUTH_TOKEN` | OAuth2 access token (if using OAuth) |
| Webhook Password | `OCE_SINCH_FAX_WEBHOOK_PASSWORD` | HTTP Basic Auth password for incoming webhooks (accepts bcrypt hash or plaintext) |

In database mode, API Secret and OAuth Token are encrypted at rest. Webhook Password is stored as a bcrypt hash. In file/environment modes, the deployment platform (e.g., Kubernetes Secrets) is responsible for protecting these values.

### Mode 1: Database (Default)

Navigate to **Administration > Globals > OpenCoreEMR Sinch Fax Module**, configure the settings, and save.

![Configuration Settings](.docs/screenshots/configuration.png)

### Mode 2: YAML Files

Mount YAML files at the conventional paths. The module auto-detects their presence — no activation flag needed.

| Path | Purpose | K8s Source |
|------|---------|------------|
| `/etc/oce/sinch-fax/config.yaml` | General settings | ConfigMap |
| `/etc/oce/sinch-fax/secrets.yaml` | Secrets | Secret |

Override paths with `OCE_SINCH_FAX_CONFIG_FILE` and `OCE_SINCH_FAX_SECRETS_FILE`.

**Example config.yaml:**
```yaml
imports:
  - { resource: secrets.yaml }
enabled: true
project_id: "abc123"
region: global
default_retry_count: 3
auth_method: basic
webhook_username: "sinch"
```

**Example secrets.yaml:**
```yaml
api_key: "your-api-key"
api_secret: "your-api-secret"
webhook_password: "$2y$10$..."
```

Config files support Symfony-style `imports` for splitting across files (paths resolve relative to the importing file). Keys in the parent file override imported keys.

Even when using YAML files, any `OCE_SINCH_FAX_*` environment variable still takes precedence over the file value.

### Mode 3: Environment Variables Only

Set `OCE_SINCH_FAX_ENV_CONFIG=1` to use pure environment variable configuration without YAML files. Then set each `OCE_SINCH_FAX_*` variable listed in the tables above.

## Usage

### Accessing the Module

Once installed and configured, you can access the module from the **Modules** menu:

![Module Menu](.docs/screenshots/module-menu.png)

### Sending a Fax

The module supports the following file formats: **PDF, TIFF, PNG, JPEG, DOC, and DOCX**.

#### From Patient Documents (Recommended)

In real-world usage, you'll typically send faxes directly from a patient's document:

1. Navigate to a patient's **Documents** tab
2. Select the document you want to fax
3. Click the **Send Fax** button in the document viewer toolbar

![Document Fax Button](.docs/screenshots/document-fax-button.png)

4. In the Send Fax dialog, the document and patient are already pre-filled
5. Enter the recipient fax number in E.164 format (e.g., +12345678901)
6. Click **Send Fax**

![Document Fax Dialog](.docs/screenshots/document-fax-dialog.png)

#### From the Module Interface

Alternatively, you can upload and send files directly:

1. Navigate to **Modules > OpenCoreEMR Sinch Fax**
2. Click the **Send Fax** tab
3. Enter the recipient fax number(s)
4. Select or upload the file(s) to fax
5. Optionally link to a patient record
6. Click **Send Fax**

![Send Fax Interface](.docs/screenshots/send-fax.png)

Upon successful submission, you'll receive a fax ID that can be used to track the fax in progress.

> **Note:** The screenshots show demo data. Patient information displayed is for demonstration purposes only, and `+19898989898` is Sinch's demo fax number for testing.

### Receiving Faxes

Incoming faxes are automatically received via webhook and stored in the module:

1. Navigate to **Modules > OpenCoreEMR Sinch Fax**
2. Click the **Fax List** tab to view all received faxes
3. Locate the received fax you want to assign to a patient
4. Click the **Move to Patient** button
5. Select the patient to associate the fax with

![Fax List with Received Faxes](.docs/screenshots/fax-list-received.png)

Once moved to a patient, the fax will:
- Appear in the patient's **Documents** tab under the **Received Faxes** category
- Remain visible in the Fax List but marked as **"Moved to Patient X"** in the Actions column
- Be managed like any other patient document in the patient's chart

![Received Faxes in Patient Documents](.docs/screenshots/patient-documents-received-faxes.png)

### Viewing Faxes

1. Navigate to **Modules > OpenCoreEMR Sinch Fax**
2. Click the **Fax List** tab to view all sent and received faxes
3. The list shows direction, fax ID, recipient/sender, status, pages, and timestamp

**Fax States:**
- **Unread** - New inbound faxes (highlighted with bold text)
- **Read** - Viewed faxes (outbound faxes default to read)
- **Archived** - Hidden from default view

**Filtering:**
- Filter by direction (Inbound/Outbound)
- Toggle "Show Archived" to include archived faxes

**Bulk Actions:**
- Select multiple faxes using checkboxes
- Mark as Read, Mark as Unread, or Archive in bulk

**Automatic Actions:**
- Faxes are automatically marked as read when downloaded/viewed
- Reconciliation runs on page load to detect any faxes missed by webhooks

![Fax List](.docs/screenshots/fax-list.png)

## Security

- **Credential protection**: In database mode, API Secret and OAuth Token are encrypted at rest using OpenEMR's `CryptoGen`, and Webhook Password is stored as a bcrypt hash. In file/environment modes, protect secrets through your deployment platform (e.g., Kubernetes Secrets, sealed secrets, or vault injection).
- **Fax file storage**: Files are stored with restricted filesystem permissions.
- **Upload validation**: All uploaded files are validated before processing.
- **Webhook authentication**: Incoming webhooks are verified with HTTP Basic Auth and an optional IP allowlist (supports CIDR notation).
- **Audit trail**: All fax operations are logged for compliance.

## Support

- Email: support@opencoreemr.com
- Issues: https://github.com/opencoreemr/oce-module-sinch-fax/issues

## License

GNU General Public License v3.0 or later

## Credits

Developed by OpenCoreEMR Inc
