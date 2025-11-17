# OpenEMR Sinch Fax Module

A secure fax integration module for OpenEMR using the Sinch Fax API.

## Features

- **Send Faxes**: Send faxes to one or multiple recipients with support for cover pages
- **Receive Faxes**: Automatically receive and store incoming faxes
- **Webhook Support**: Real-time notifications for fax status updates
- **Multiple File Formats**: Support for PDF, Word, TIF and other document types
- **Cover Pages**: Create and use custom cover pages with dynamic fields
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
5. Find "Sinch Fax" in the list and click **Register**
6. Click **Install**
7. Click **Enable**

### Manual Installation

1. Download the latest release
2. Extract to `openemr/interface/modules/custom_modules/oce-module-sinch-fax`
3. Follow steps 3-7 from the Composer installation

## Configuration

1. Navigate to **Administration > Globals > Sinch Fax**
2. Configure the following settings:
   - **Sinch Project ID**: Your Sinch project ID
   - **Sinch Service ID**: Your Sinch service ID
   - **API Authentication**: Choose Basic Auth or OAuth2
   - **API Key/Secret**: Your Sinch API credentials
   - **API Region**: Select your preferred region (or leave as 'global')
   - **Webhook URL**: URL where Sinch will send notifications (auto-configured)

3. Save the settings

## Usage

### Sending a Fax

1. Navigate to the patient chart or document you want to fax
2. Click the **Send Fax** button
3. Enter recipient fax number(s)
4. Optionally select a cover page
5. Click **Send**

### Viewing Faxes

1. Navigate to **Modules > Sinch Fax**
2. View all sent and received faxes
3. Filter by date, direction, status, or patient
4. Click on a fax to view details or download

### Managing Cover Pages

1. Navigate to **Modules > Sinch Fax > Cover Pages**
2. Upload a PDF cover page template
3. Use template tags like `{{from}}`, `{{to}}`, `{{date}}` for dynamic content

## Security

- API credentials are encrypted in the database
- Fax files are stored with restricted permissions
- All file uploads are validated
- Webhook endpoints verify Sinch authentication
- Audit logging for all fax operations

## Support

- Email: support@opencoreemr.com
- Issues: https://github.com/opencoreemr/oce-module-sinch-fax/issues

## License

GNU General Public License v3.0 or later

## Credits

Developed by OpenCoreEMR Inc
