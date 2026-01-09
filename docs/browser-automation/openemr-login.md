# OpenEMR Login Process

This document describes how to log into OpenEMR using browser automation.

## Prerequisites

1. Docker environment running: `task dev:start`
2. Know the port: `task dev:port` (e.g., `http://localhost:51791`)

## Default Credentials

| Field | Value |
|-------|-------|
| Username | `admin` |
| Password | `pass` |

## Login Steps

### 1. Navigate to OpenEMR

```
URL: http://localhost:{PORT}
```

This automatically redirects to the login page at:
```
/interface/login/login.php?site=default
```

### 2. Identify Form Elements

Use `read_page` with `filter: interactive` to find:

| Element | Type | Identifier |
|---------|------|------------|
| Username | textbox | `placeholder="Username"` |
| Password | textbox | `type="password"` |
| Login | button | `type="submit"` |

Example element references (may vary):
- Username: `ref_7`
- Password: `ref_9`
- Login button: `ref_49`

### 3. Fill and Submit

```javascript
// Using form_input tool:
form_input(ref="ref_7", value="admin")  // Username
form_input(ref="ref_9", value="pass")   // Password
click(ref="ref_49")                      // Login button
```

### 4. Verify Login Success

After successful login:
- Page title changes to "OpenEMR"
- URL changes to `/interface/main/tabs/main.php?token_main=...`
- Calendar view is displayed by default

## Common Issues

### Menu Not Working After Login

**Symptom:** Dropdown menus don't respond to clicks

**Solution:** Log out and log back in. This can happen if the session state gets corrupted.

### Session Timeout

**Symptom:** Redirected to login page unexpectedly

**Solution:** Re-authenticate. OpenEMR sessions expire after inactivity.

### Frame Issues

**Symptom:** Content not loading or blank areas

**Solution:** OpenEMR uses iframes extensively. Ensure you're interacting with the correct frame context.

## Example Browser Automation Sequence

```python
# 1. Get tab context
tabs_context_mcp(createIfEmpty=True)

# 2. Navigate to OpenEMR
navigate(url="http://localhost:51791", tabId=TAB_ID)

# 3. Wait for page load
wait(duration=2)

# 4. Read page to get element references
read_page(tabId=TAB_ID, filter="interactive")

# 5. Fill username
form_input(ref="ref_7", value="admin", tabId=TAB_ID)

# 6. Fill password
form_input(ref="ref_9", value="pass", tabId=TAB_ID)

# 7. Click login
click(ref="ref_49", tabId=TAB_ID)

# 8. Wait for redirect
wait(duration=2)

# 9. Verify - take screenshot
screenshot(tabId=TAB_ID)
```

## Post-Login State

After successful login, the main interface shows:

- **Top Menu Bar:** Calendar, Finder, Flow, Recalls, Messages, Patient, Fees, Modules, Procedures, Admin, Reports, Miscellaneous, Popups
- **Tab Bar:** Shows open tabs (Calendar, Message Center by default)
- **Main Content Area:** Calendar view with daily schedule
- **Left Sidebar:** Provider list and facility info
