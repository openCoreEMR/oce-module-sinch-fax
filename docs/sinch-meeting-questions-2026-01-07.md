# Sinch Meeting Questions - January 7, 2026

## Context

OpenCoreEMR is building HIPAA-compliant fax and SMS/RCS integration for healthcare customers. Our plan:
- Use **subprojects** for each customer within our Sinch account
- Each customer gets a fax number and SMS/RCS number
- Secure webhooks for real-time notifications
- Automated token generation and rotation per customer

---

## Open Questions

### General

1. **Please include new APIs in llms.txt**
   - Only SMS, Numbers, Conversation, Voice, Verification, Provisioning are listed
   - Fax, Subprojects, Keys are not
   - Makes AI-assisted development harder

### Webhook Authentication

2. **How does Sinch authenticate webhook callbacks to our endpoints?**
   - Fax API docs mention webhooks, but I can't tell from the docs or dashboard how to tell Sinch how to secure and authenticate requests to our services
   - Conversation API mentions webhooks but security details are missing
   - **Specifically needed:** HMAC signature headers, validation algorithm, shared secret configuration

3. **Is there HMAC signature validation for Fax webhooks?**
   - Conversation API appears to have it (x-sinch-webhook-signature headers) - does Fax API?
   - If not, what alternatives exist for HIPAA-compliant webhook verification?

4. **What are Sinch's IP ranges for webhook callbacks?**
   - If signature validation isn't available, IP allowlisting may be needed as a fallback
   - Need documented, stable IP ranges

### Subproject Management at Scale

5. **Can we programmatically create subprojects via API?**
   - Docs show the endpoint exists (`POST /v1alpha1/projects/{parentProjectId}/subprojects`)
   - Please grant us access to this API
   - Are there rate limits?
   - Are there quotas on number of subprojects?

6. **Can we deactivate a subproject without deleting it?**
   - Current docs only show DELETE - no soft deactivation

### Access Keys & Token Rotation

7. **What's the recommended token rotation strategy?**
   - Access Keys API docs don't mention rotation
   - Can we create a new key and delete the old one atomically?
   - What's the overlap window for key transitions?

8. **We need to scope access keys to specific subprojects**
   - Docs don't mention permissions/scopes at all
   - Need: Can subproject keys be limited to only that subproject's resources?

9. **Are there API rate limits for Access Keys operations?**
   - We'd be creating/rotating keys for many customers
   - Need to know limits before designing automation

### Fax-Specific Questions

10. **Is `faxi.api.sinch.com` correct for HIPAA?**
    - HIPAA docs reference `faxi.api.sinch.com`
    - **DNS lookup shows this domain doesn't exist** - is this a documentation error?
    - Currently using `fax.api.sinch.com` - is that HIPAA-compliant?

11. **Can we configure per-service webhook URLs?**
    - Need different webhook endpoints for different customers (subprojects)
    - How do fax "services" relate to projects/subprojects?



---

"Bundles API"

34.232.249.173
 44.226.9.173

fax webhook url ips

---

porting -- for moving a number into sinch
