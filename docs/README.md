# Sinch Fax Module Documentation

This directory contains detailed documentation for the Sinch Fax module. For a quick overview and index, see [CLAUDE.md](../CLAUDE.md) in the project root.

## Directory Structure

```
docs/
├── browser-automation/      # AI agent navigation guides
│   ├── openemr-login.md    # Login process and credentials
│   └── openemr-navigation.md # Menu navigation patterns
├── development/             # Development patterns and setup
│   ├── architecture.md     # Module structure, entry points
│   ├── controllers.md      # Controller patterns, Request/Response
│   ├── exceptions.md       # Exception handling
│   ├── templates.md        # Twig templates
│   ├── database.md         # Database access via QueryUtils
│   ├── openemr-integration.md # OpenEMR integration, dependencies
│   ├── code-quality.md     # Code standards, security
│   ├── docker.md           # Docker environment
│   ├── tooling.md          # Taskfile/Composer scripts
│   └── unit-tests.md       # Testing patterns, mocks
├── sinch/                   # Sinch API documentation
│   └── api-reference.md    # API links and guidance
└── testing/                 # Testing workflows
    ├── sinch-fax-testing.md # End-to-end fax testing
    └── webhook-testing.md   # Webhook testing with Tailscale
```

## Quick Reference

### Development Environment

```bash
# Start Docker environment
task dev:start

# Get OpenEMR URL
task dev:port

# Default credentials
# Username: admin
# Password: pass
```

### Key URLs (relative to OpenEMR root)

| Page | Path |
|------|------|
| Login | `/interface/login/login.php` |
| Main (after login) | `/interface/main/tabs/main.php` |
| Admin Config | `/interface/super/edit_globals.php` |
| Sinch Fax Module | `Modules > OpenCoreEMR Sinch Fax` |
| Module Config | `Admin > Config > OpenCoreEMR Sinch Fax Module` |

### Menu Structure

```
Top Menu Bar:
├── Calendar
├── Finder
├── Flow
├── Recalls
├── Messages
├── Patient
├── Fees
├── Modules
│   ├── Manage Modules
│   ├── Carecoordination
│   └── OpenCoreEMR Sinch Fax  ← Main module entry
├── Procedures
├── Admin
│   ├── Config  ← Module settings here
│   ├── Clinic
│   ├── Patients
│   ├── Practice
│   ├── Coding
│   ├── Forms
│   ├── Documents
│   ├── System
│   ├── Users
│   ├── Address Book
│   └── ACL
├── Reports
├── Miscellaneous
└── Popups
```

## For AI Agents

When navigating OpenEMR with browser automation:

1. **Always get tab context first** - Use `tabs_context_mcp` before any browser operations
2. **Use element references** - Prefer `ref_*` identifiers from `read_page` over coordinates
3. **Wait after navigation** - OpenEMR loads content in iframes, wait 2-3 seconds after clicks
4. **Handle dropdowns carefully** - Menu items may require hovering first
5. **Check for dialogs** - OpenEMR uses modal dialogs that can block interaction

See individual documentation files for detailed procedures.
