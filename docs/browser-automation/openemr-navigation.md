# OpenEMR Navigation Guide

This document describes how to navigate OpenEMR's menu system using browser automation.

## Menu System Overview

OpenEMR uses a horizontal menu bar with dropdown submenus. Some menus have nested submenus (indicated by arrows).

## Navigation Patterns

### Opening Dropdown Menus

1. **Click the menu button** in the top navigation bar
2. **Wait briefly** (menu animates open)
3. **Read page** to get updated element references
4. **Click the menu item**

### Important: Element References Change

After clicking a menu button, the dropdown items get new `ref_*` identifiers. Always call `read_page` after opening a dropdown to get current references.

## Key Navigation Paths

### Access Sinch Fax Module

**Path:** Modules > OpenCoreEMR Sinch Fax

```
1. Click "Modules" button (ref varies)
2. Wait 1 second
3. Read page to find "OpenCoreEMR Sinch Fax"
4. Click the Sinch Fax menu item
```

### Access Module Configuration

**Path:** Admin > Config > (scroll to) OpenCoreEMR Sinch Fax Module

```
1. Click "Admin" button
2. Wait 1 second
3. Read page to find "Config" item
4. Click "Config"
5. Wait for Config page to load (2-3 seconds)
6. In left sidebar, scroll down to find "OpenCoreEMR Sinch Fax Module"
7. Click on it to view/edit settings
```

## Config Page Structure

The Admin > Config page has a left sidebar with configuration sections:

```
Left Sidebar Sections:
├── Appearance
├── Branding
├── Login Page
├── Locale
├── Features
├── Report
├── Billing
├── E-Sign
├── Documents
├── Calendar
├── Insurance
├── Security
├── Notifications
├── CDR
├── Logging
├── Miscellaneous
├── Portal
├── Connectors
├── Rx
├── PDF
├── Patient Banner Bar
├── Encounter Form
├── Questionnaires
├── Carecoordination
└── OpenCoreEMR Sinch Fax Module  ← Module settings
```

## Sinch Fax Module Settings

When viewing the module configuration (Admin > Config > OpenCoreEMR Sinch Fax Module):

| Setting | Description | Required |
|---------|-------------|----------|
| Enable Sinch Fax | Master enable/disable toggle | Yes |
| Sinch Project ID | Your Sinch project identifier | Yes |
| Sinch Service ID | Service identifier | **No** (not crucial for fax) |
| API Key | Sinch API key | Yes |
| API Secret | Sinch API secret | Yes |
| API Region | `global`, `us`, `eu` | Yes |
| File Storage Path | Where to store fax files | Optional |
| Default Retry Count | Retry attempts for failed faxes | Yes |

**Note:** The Service ID field is optional and not required for fax functionality. Don't flag an empty Service ID as a configuration problem.

## Critical Rules

### NEVER Use Direct URL Navigation

**CRITICAL:** Never use the browser's `navigate` tool to go directly to an OpenEMR URL. This will trigger a dialog box that blocks all further browser automation.

**Wrong:**
```
navigate to http://localhost:53527/interface/modules/custom_modules/oce-module-sinch-fax/public/index.php
```

**Correct:**
```
1. Click "Modules" in the menu bar
2. Click "OpenCoreEMR Sinch Fax" in the dropdown
```

Always use OpenEMR's menu system and tab navigation. The only exception is the initial login page.

### Never Refresh the Page Directly

Similarly, never use browser refresh. Use OpenEMR's built-in tab reload icons (the ↻ symbol next to each tab name in the OpenEMR tab bar).

## Common Navigation Issues

### Dropdown Menu Not Responding

**Problem:** Clicking menu items doesn't work

**Solutions:**
1. Log out and log back in
2. Try clicking the parent menu button first
3. Use JavaScript execution as fallback:
   ```javascript
   document.querySelectorAll('a').forEach(a => {
     if (a.textContent.trim() === 'Config') a.click();
   });
   ```

### Calendar Dialog Popup

**Problem:** Clicking in the main area opens an appointment dialog

**Solution:** The calendar area is interactive. Click only on menu items or specific UI controls. Close dialogs by clicking the X button or pressing Escape.

### Content Not Updating

**Problem:** Clicked menu item but content didn't change

**Solutions:**
1. Wait longer (3-5 seconds) for iframe content to load
2. Take a screenshot to verify current state
3. Check if a new tab opened in OpenEMR's tab bar

## Tips for AI Agents

1. **Take screenshots frequently** - Visual confirmation helps verify state
2. **Use `read_page` with depth limits** - Full page reads can be large
3. **Prefer `filter: interactive`** - Gets just clickable elements
4. **Don't trust cached refs** - Element references change after navigation
5. **Watch for nested frames** - OpenEMR loads content in iframes
6. **Handle errors gracefully** - Menu issues often resolve with logout/login
