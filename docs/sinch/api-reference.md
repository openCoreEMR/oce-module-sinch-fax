# Sinch API Documentation

## For AI Agents

The Fax API works alongside other Sinch APIs for complete functionality.

**Fax API** (primary for this module):
- Docs: https://developers.sinch.com/docs/fax/api-reference/fax.md
- Use for: Sending/receiving faxes, checking status, downloading fax content
- **Note:** Fax API is NOT included in llms.txt - consult web docs directly

**Numbers API** - Required for fax number management:
- Docs: https://developers.sinch.com/docs/numbers/api-reference/numbers.md
- Use for: Searching, purchasing, and configuring fax-capable numbers
- Included in llms.txt

**Related APIs in llms.txt** (https://developers.sinch.com/llms.txt):
- Numbers API - for managing fax-capable phone numbers
- Verification API - for validating destination numbers
- Provisioning API - for configuring accounts and services

**Additional APIs (not in llms.txt):**

- **Subproject API**: For multi-tenant setups and resource isolation
  - Docs: https://developers.sinch.com/docs/subproject/api-reference/subproject.md

- **Access Keys API**: For managing API keys and permissions
  - Docs: https://developers.sinch.com/docs/accesskeys/api-reference/accesskeys.md

- **Projects API**: For project configuration
  - Docs: https://developers.sinch.com/docs/account/api-reference/projects.md

**When to use:**
- Implementing fax send/receive features → Fax API docs
- Managing fax numbers → Numbers API (llms.txt or web docs)
- Multi-tenant provisioning → Subproject/Access Keys APIs (web docs)
