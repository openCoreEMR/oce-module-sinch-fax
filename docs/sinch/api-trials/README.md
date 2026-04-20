# Sinch API Trials — Empirical Findings

This directory captures live API trials performed against the Sinch production account that hosts the SmiTTY test tenant. The work was driven by ADR-023 (Sinch fax provisioning automation in `oce-py-tenant-onboarder`); the findings are recorded here so the `oce-module-sinch-fax` codebase has a self-contained record of what the live API actually does — independent of Sinch's published documentation, which the trials repeatedly contradicted.

Every claim below is backed by a script in [`scripts/`](./scripts/) and a captured response log in [`outputs/`](./outputs/). When this document and the docs disagree, trust the captures.

## Account under test

| Field | Value |
|------|------|
| Project ID | `REDACTED-PROJECT-ID` |
| Access key ID | `REDACTED-ACCESS-KEY-ID` (project-scoped) |
| Existing fax service | `01KA9HS6PEVQDSBEWWZ3AT61RF` (`Default Service`, `defaultForProject: true`) |
| Existing voice app | `a64561f0-d691-4fc0-b013-bcec5f12f1bc` (RTC) |
| Existing active numbers | `+12082799895`, `+18456135708` (both LOCAL, both SMS+VOICE, $1.00/mo, no E911) |

## Methodology

- Each trial is a single-purpose Python script with a confirm prompt, masked-secret logging, JSON-per-line output to stdout, and (where state is created) automatic teardown. The captured `outputs/*.log` files are interleaved stdout+stderr transcripts (Poetry preamble, the confirm prompt, then the JSON event stream), not pure JSONL — `grep '^{' file.log | jq` to reduce to parseable JSON.
- All trials use OAuth2 Bearer tokens against `https://auth.sinch.com/oauth2/token`. Basic was used initially but produced silent permission contradictions on destructive verbs — see Probe 1 / 1c.
- The probes were intentionally small: each one was designed to refute a single hypothesis, not to validate an end-to-end flow.

## Trials in chronological order

### Probe 1 — Subproject create + delete (Basic auth)

**Hypothesis:** `POST /v1alpha1/projects/{parent}/subprojects` and `DELETE /v1alpha1/subprojects/{id}` work with the project-scoped access key.

**Script:** [`scripts/probe_01_subproject.py`](./scripts/probe_01_subproject.py)
**Output:** [`outputs/probe_01_output.log`](./outputs/probe_01_output.log)

**Outcome:**
- `POST` with Basic auth → **200**, returned `subprojectId: REDACTED-SUBPROJECT-ID`.
- `DELETE` with the same Basic credentials → **403 PERMISSION_DENIED** with empty `message` and empty `details`.
- Created subproject was orphaned.

The asymmetry was the surprise — same key, same project, create works, delete doesn't.

### Probe 1b — Alternate teardown shapes

**Hypothesis:** Either soft-delete via `PATCH ?update_mask=deleted` or a namespaced `DELETE /v1alpha1/projects/{parent}/subprojects/{id}` URL works.

**Script:** [`scripts/probe_01b_subproject_delete.py`](./scripts/probe_01b_subproject_delete.py)
**Output:** [`outputs/probe_01b_output.log`](./outputs/probe_01b_output.log)

**Outcome:** Both attempts failed.
- PATCH soft-delete → **400** (`failed to marshal error message`).
- Namespaced DELETE → **404 NOT_FOUND**.

The orphan still existed and the spec-shaped DELETE URL is plainly the right one. The blocker is something else.

### Probe 1c — DELETE with OAuth2 Bearer

**Hypothesis:** The same `DELETE /v1alpha1/subprojects/{id}` works if we authenticate with an OAuth2 bearer token instead of HTTP Basic.

**Script:** [`scripts/probe_01c_bearer_delete.py`](./scripts/probe_01c_bearer_delete.py)
**Output:** [`outputs/probe_01c_output.log`](./outputs/probe_01c_output.log)

**Outcome:** **200**. The orphan was deleted. A follow-up GET confirmed `deleted: true`.

**Empirical finding (load-bearing for everything that follows):** Sinch's destructive verbs (DELETE on subproject, at minimum) silently 403 on Basic auth and accept Bearer. Both auth schemes are listed in the spec; the docs imply equivalence; behaviour is not equivalent. **Default to OAuth2 Bearer for every Sinch API. Treat Basic as informally for-reads-only.**

### Probe 2 — Access keys scoped to a fresh subproject

**Hypothesis:** A project-scoped access key on the parent project can mint additional access keys inside a freshly-created subproject (i.e., per-tenant credential issuance is possible).

**Script:** [`scripts/probe_02_access_key.py`](./scripts/probe_02_access_key.py)
**Output:** [`outputs/probe_02_output.log`](./outputs/probe_02_output.log)

**Outcome:**
- Subproject create → **200** (using Bearer this time).
- `POST /v1/projects/{subprojectId}/accessKeys` → **403 PERMISSION_DENIED**.
- Subproject deleted cleanly. No orphans.

**Empirical finding:** A project-scoped key cannot mint keys inside its own subprojects. Per-tenant access-key issuance via the API is not viable from this credential scope. (Whether an account-level admin credential could do it is untested.)

### Probe 3 — Fax Service CRUD with webhook Basic Auth

**Hypothesis:** The Fax Service v3 endpoints (`/v3/projects/{projectId}/services`) support full CRUD and accept HTTP-Basic-style webhook URLs (`https://user:pass@host/path`).

**Script:** [`scripts/probe_03_fax_service.py`](./scripts/probe_03_fax_service.py)
**Output:** [`outputs/probe_03_output.log`](./outputs/probe_03_output.log)

**Outcome:** All steps passed; zero orphans.
- LIST pre-state → 1 existing service (`Default Service` / `01KA9HS6PEVQDSBEWWZ3AT61RF`).
- CREATE with `incomingWebhookUrl=https://<user>:<pass>@example.invalid/fax-callback` → **201**.
- GET → **200**.
- PATCH (rename + new webhook URL, no `updateMask` query parameter) → **200**.
- DELETE → **204**.
- LIST post-state → original 1 service.

**Empirical findings:**
- Fax Service v3 PATCH does not require an `updateMask` query parameter (unlike most Google-style AIP services).
- DELETE returns **204 No Content**, not 200.
- **Sinch redacts URL-embedded credentials as `***:***` on every read of `incomingWebhookUrl` — both in the POST response body and on subsequent GETs.** Implication: once the provisioner stores those credentials, it cannot read them back from the API; the credentials must be persisted independently (e.g., GCP Secret Manager) at provision time.

### Probe 4 — Available numbers search (with area-code arg)

**Hypothesis:** `GET /v1/projects/{projectId}/availableNumbers` with `?numberPattern.pattern=212&numberPattern.searchPattern=START` returns NYC numbers.

**Script:** [`scripts/probe_04_number_search.py`](./scripts/probe_04_number_search.py)
**Output:** [`outputs/probe_04_output.log`](./outputs/probe_04_output.log)

**Outcome:** **200** with `availableNumbers: []` — empty.

**Finding (mechanical, not empirical):** The `numberPattern.pattern` parameter is matched against the **full E.164 string** (`+12025550134`), not the bare area-code substring. Matching `START=212` against US numbers (which all begin `+1`) cannot succeed. Subsequent probes use `+1XXX` prefixes.

### Probe 4b — Available numbers search (no filter)

Re-ran probe 4 with no area-code argument. (No new script — same probe, different invocation.)

**Output:** [`outputs/probe_04b_output.log`](./outputs/probe_04b_output.log)

**Outcome:** **200**, 10 NJ-201 numbers, all SMS+VOICE, $0.50 setup + $0.50/mo, **all `supportingDocumentationRequired: true`**.

**Finding:** The flag may be the gating factor — if true on every available number, the API caller can't just `:rent`. This was the central question Probes 4c, 5, and 6 were designed to falsify.

### Probe 4c — Active numbers + docs-free hunt

**Hypothesis A:** Some US LOCAL VOICE inventory has `supportingDocumentationRequired: false`; Probe 4b's sample was unlucky.
**Hypothesis B:** The dashboard performs an extra step the API caller must do (E911 provisioning, account-level KYC).

**Script:** [`scripts/probe_04c_active_numbers_and_inventory.py`](./scripts/probe_04c_active_numbers_and_inventory.py)
**Output:** [`outputs/probe_04c_output.log`](./outputs/probe_04c_output.log)

**Outcome:**
- Phase A (existing active numbers): both `+12082799895` and `+18456135708` returned **404 "Missing E911 feature"** on `GET .../emergencyAddress`. Their `voiceConfiguration.type` is `RTC` with the SmiTTY appId — neither uses `type: "FAX"`. The dashboard-rented fax number works **without** any emergency address.
- Phase B (docs-free hunt across +1201, +1415, +1312, +1702, +1307, US TOLL_FREE): **40 of 40** numbers returned by the four populated searches had `supportingDocumentationRequired: true`. SF and Chicago returned empty (inventory exhaustion, unrelated). **Zero docs-free numbers found.**

**Findings:**
- E911 is not a prerequisite for a working number on this account. The earlier line of inquiry into emergency addresses was a dead end for fax provisioning — fax traffic does not dial 911.
- H1 (some inventory is docs-free) is refuted by the sample. Either the flag is misleading, or it doesn't actually gate `:rent`. Probe 6 resolved this.

### Probe 5 — `lookupNumberRequirements` for US

**Hypothesis:** `POST /v1/projects/{projectId}/numberOrders:lookupNumberRequirements` returns the JSON Schema describing what data US LOCAL and US TOLL_FREE need to satisfy the docs flag.

**Script:** [`scripts/probe_05_number_requirements.py`](./scripts/probe_05_number_requirements.py)
**Output:** [`outputs/probe_05_output.log`](./outputs/probe_05_output.log)

**Outcome:** Both lookups returned **404 "Number requirements not found"**.

**Finding:** Sinch reports no documented per-region requirements for US LOCAL or US TOLL_FREE — directly contradicting the `supportingDocumentationRequired: true` flag carried by every available US number. The two pieces of API state are inconsistent. Most likely explanations: the flag is advisory; the gating is account-level (10DLC for SMS, already done — see `smsConfiguration.servicePlanId: "OpenCoreEMR_RA"` and `campaignId: "CO07D9V"` on the existing active numbers); or there is no gating at all on this account.

### Probe 6 — Rent + immediate release (write probe)

**Hypothesis:** Despite `supportingDocumentationRequired: true`, `:rent` succeeds for a US LOCAL VOICE number on this account. (Refutation: any non-2xx response on `:rent`.)

**Script:** [`scripts/probe_06_rent_and_release.py`](./scripts/probe_06_rent_and_release.py)
**Output:** [`outputs/probe_06_output.log`](./outputs/probe_06_output.log)

**Method:** Search Wyoming `+1307`, take the first candidate (`+13072074530`, $0.50 setup + $0.50/mo, `supportingDocumentationRequired: true`), POST `:rent` with `voiceConfiguration: {type: "RTC", appId: "<SmiTTY appId>"}` (mirroring the dashboard's apparent behaviour), then immediately POST `:release`.

**Outcome:**
- `:rent` → **200**. Number became active. `voiceConfiguration.appId` was returned as `""` with `scheduledVoiceProvisioning: {appId: "<SmiTTY appId>", status: "WAITING", type: "RTC"}` — voice binding is **asynchronous**.
- `:release` → **200**. Response set `expireAt: "2026-05-19T21:38:22Z"` (~30 days out).

**Findings:**
- **`supportingDocumentationRequired: true` does NOT block `:rent` on this account.** The flag is misleading. The simple `:rent` call is the correct primitive for the provisioner; the Number Order chain (`createNumberOrder` → `registration` → `submit`) is not required for US LOCAL VOICE.
- **`:release` is not an undo.** It cancels auto-renewal and sets `expireAt` to the existing `nextChargeDate`. The number stays in `activeNumbers` for the remainder of the paid month, and **Sinch does not refund** the setup fee or the prorated month. Net cost of this probe: $1.00 (charged to the project; `+13072074530` is reachable until 2026-05-19, then drops back to inventory).
- **Voice config is async.** A provisioner that needs the voice binding to be live before reporting success must poll `GET .../activeNumbers/{phone}` until `scheduledVoiceProvisioning` clears (untested — leaves an open question, see below).

## Synthesized findings (what we now know)

1. **Auth:** OAuth2 Bearer for everything. Basic silently 403s destructive verbs.
2. **Subprojects:** create + delete work, but a project-scoped key cannot mint child access keys — per-tenant API credentials via subprojects is not viable from the credential scope we have today.
3. **Fax Services v3:** standard CRUD; PATCH needs no updateMask; DELETE returns 204; **webhook URL credentials are write-only from the API's perspective** (read-back is `***:***`).
4. **Numbers — search:** `numberPattern.pattern` is matched against the full E.164 string. Use `+1XXX` for US area-code searches.
5. **Numbers — `supportingDocumentationRequired`:** misleading on this account. Carries `true` on 100% of sampled US LOCAL VOICE inventory but does not gate `:rent`. `lookupNumberRequirements` returns 404 for US, consistent with "no gating present."
6. **Numbers — rent:** single `POST .../availableNumbers/{phone}:rent` with a `voiceConfiguration` body activates the number. Voice binding is async (`scheduledVoiceProvisioning.status: "WAITING"` until resolved).
7. **Numbers — release:** cancels auto-renewal only. The number stays active until `expireAt`. Costs paid are not refunded. **There is no rent-undo within the paid month.**

## Open questions

- How long does `scheduledVoiceProvisioning` typically take to clear, and what happens if a fax message arrives during the WAITING window? (Untested. A small follow-up probe could rent + GET in a poll loop, then release.)
- Does `:rent` with `voiceConfiguration.type: "FAX"` and `serviceId` (instead of `type: "RTC"` + `appId`) behave differently from the dashboard's RTC routing? The existing SmiTTY fax number is bound via RTC, not FAX — so the FAX variant of the schema is unexercised by both the user's existing setup and these probes.
- Is there an account-level (not project-level) credential that can mint per-subproject access keys? If yes, per-tenant Sinch projects + per-tenant credentials becomes viable for the provisioner; if no, all tenants share one project and isolation is per-fax-service only.
- Does `supportingDocumentationRequired` ever matter for US LOCAL VOICE on a different account (e.g., one without 10DLC pre-registration)? The flag is account-state-dependent in some way we can't see from inside this account.

## Reproducing the trials

Each script is self-contained. Required environment:

```bash
export SINCH_PROJECT_ID=<your-project-id>
export SINCH_ACCESS_KEY_ID="$(op read 'op://OCE Dev Tenant Systems/Sinch Keys/add more/SmiTTY Test Key Id')"
export SINCH_ACCESS_KEY_SECRET="$(op read 'op://OCE Dev Tenant Systems/Sinch Keys/add more/SmiTTY Test Key Secret')"
```

Python ≥3.13 with `httpx` (the original probes were run via the `oce-py-tenant-onboarder` Poetry environment). Each script accepts `--yes` to skip its confirmation prompt; without it, every script prints a plan and waits for `y` on stdin.

**Probe 6 incurs ~$1.00 of real Sinch billing.** The other scripts are read-only or use teardown to reach zero net state. Verify the confirm-prompt summary before approving any run.
