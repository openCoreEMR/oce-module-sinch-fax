#!/usr/bin/env python3
"""Probe 6 for ADR-023: rent + release a US LOCAL number (write probe).

Resolves the contradiction left by Probes 4b/4c/5:

  * 100% of sampled US inventory advertises `supportingDocumentationRequired:
    true` (probe 4b/4c).
  * `lookupNumberRequirements` for US LOCAL and US TOLL_FREE both 404
    "Number requirements not found" (probe 5).
  * The user rented existing numbers via the dashboard with no document
    upload, no E911, no registration form. Both active numbers run with
    `voiceConfiguration.type: "RTC"` + appId (NOT `type: "FAX"`).

Hypothesis being tested:
  H3 — `supportingDocumentationRequired: true` does NOT block `:rent` on
       this account. The flag is either advisory, pre-satisfied at the
       account level, or refers to a registration that's not surfaced
       through the requirements lookup. If H3 is right, the provisioner
       can use `:rent` directly for US LOCAL VOICE numbers.

If H3 is right, the response is 200 and we immediately `:release` to
roll back. If H3 is wrong, the response is 4xx and we get a concrete
error message telling us what's actually missing. Either outcome is
informative.

Flow:
  1. Search Wyoming +1307 (cheap inventory, $0.50/mo) for a fresh candidate
  2. POST .../availableNumbers/{phone}:rent with the same RTC voice
     config the user's dashboard-rented numbers use:
       voiceConfiguration = {type: "RTC", appId: "<the SmiTTY appId>"}
  3. If rent succeeds:
       a. Log full active-number record
       b. Immediately POST .../activeNumbers/{phone}:release
       c. If release 4xx/5xx, retry once after 5s; if still failing,
          log ORPHAN_NUMBER loudly and exit non-zero
  4. If rent fails: log error and exit 0 (failure IS the answer)

Cost ceiling (worst case, release permanently fails):
    $0.50 setup + $0.50 first month = $1.00 for this probe

Usage:
    export SINCH_PROJECT_ID=<your-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_06_rent_and_release.py

Exit codes:
    0 — flow completed cleanly (rent succeeded + release succeeded, OR
        rent failed with a logged error)
    1 — config missing, aborted, token exchange failed, or no inventory
    2 — RENT SUCCEEDED BUT RELEASE FAILED — orphan number requires
        manual cleanup via Sinch dashboard
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

import httpx

NUMBERS_API_BASE = "https://numbers.api.sinch.com"
AUTH_TOKEN_URL = "https://auth.sinch.com/oauth2/token"

# The same Voice app ID currently bound to the user's two active numbers
# (per probe 04c output). Renting with this appId mirrors the dashboard
# behaviour exactly — keeps the test configuration realistic.
SMITTY_VOICE_APP_ID = "a64561f0-d691-4fc0-b013-bcec5f12f1bc"

# Wyoming had 10 candidates with $0.50/$0.50 pricing in probe 4c — cheapest
# pool we observed. If inventory has rotated we'll just take whatever 1307
# returns first.
SEARCH_REGION = "US"
SEARCH_TYPE = "LOCAL"
SEARCH_AREA_CODE_E164 = "+1307"
SEARCH_SIZE = 5

RELEASE_RETRY_DELAY_SEC = 5.0


@dataclass(frozen=True)
class ProbeConfig:
    project_id: str
    key_id: str
    key_secret: str
    numbers_api: str
    auth_url: str
    skip_prompt: bool


def utc_stamp() -> str:
    return datetime.now(tz=UTC).strftime("%Y%m%dT%H%M%SZ")


def mask(secret: str) -> str:
    if len(secret) < 8:
        return "****"
    return f"{secret[:2]}…{secret[-2:]} (len={len(secret)})"


def mask_token(token: str) -> str:
    if len(token) < 12:
        return "****"
    return f"{token[:4]}…{token[-4:]} (len={len(token)})"


def log(phase: str, status: str, **extra: Any) -> None:
    record = {"ts": utc_stamp(), "phase": phase, "status": status, **extra}
    print(json.dumps(record, default=str), flush=True)


def safe_json(resp: httpx.Response) -> Any:
    try:
        return resp.json()
    except ValueError:
        return resp.text[:2000]


def redact_token(body: Any) -> Any:
    if not isinstance(body, dict):
        return body
    out = dict(body)
    if isinstance(out.get("access_token"), str):
        out["access_token"] = mask_token(out["access_token"])
    return out


def load_config(args: argparse.Namespace) -> ProbeConfig | None:
    missing = [
        var
        for var in ("SINCH_PROJECT_ID", "SINCH_ACCESS_KEY_ID", "SINCH_ACCESS_KEY_SECRET")
        if not os.environ.get(var)
    ]
    if missing:
        log("config", "missing_env", variables=missing)
        return None
    return ProbeConfig(
        project_id=os.environ["SINCH_PROJECT_ID"],
        key_id=os.environ["SINCH_ACCESS_KEY_ID"],
        key_secret=os.environ["SINCH_ACCESS_KEY_SECRET"],
        numbers_api=args.numbers_api,
        auth_url=args.auth_url,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("ADR-023 Probe 6: rent + IMMEDIATELY release a US LOCAL number", file=sys.stderr)
    print(f"  project        : {cfg.project_id}", file=sys.stderr)
    print(f"  key            : {cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print(f"  search         : {SEARCH_REGION} {SEARCH_TYPE} {SEARCH_AREA_CODE_E164} (size {SEARCH_SIZE})", file=sys.stderr)
    print(f"  rent config    : voiceConfiguration {{type: RTC, appId: {SMITTY_VOICE_APP_ID}}}", file=sys.stderr)
    print(f"  rent rollback  : POST :release immediately on success", file=sys.stderr)
    print(f"  cost ceiling   : ~$1.00 if release permanently fails (orphan)", file=sys.stderr)
    print(f"  STATE CHANGE   : YES — this probe activates a real DID briefly", file=sys.stderr)
    print("", file=sys.stderr)
    print("Proceed? [y/N] ", file=sys.stderr, end="", flush=True)
    return sys.stdin.readline().strip().lower() == "y"


def exchange_token(cfg: ProbeConfig) -> str | None:
    log("token", "request", url=cfg.auth_url)
    auth = httpx.BasicAuth(username=cfg.key_id, password=cfg.key_secret)
    try:
        resp = httpx.post(
            cfg.auth_url,
            auth=auth,
            data={"grant_type": "client_credentials"},
            headers={"Accept": "application/json"},
            timeout=30.0,
        )
    except httpx.RequestError as err:
        log("token", "transport_error", error=repr(err))
        return None
    body = safe_json(resp)
    log("token", "response", http_status=resp.status_code, body=redact_token(body))
    if resp.status_code != 200 or not isinstance(body, dict):
        return None
    token = body.get("access_token")
    return token if isinstance(token, str) and token else None


def find_candidate(client: httpx.Client, cfg: ProbeConfig) -> dict[str, Any] | None:
    url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/availableNumbers"
    params = {
        "regionCode": SEARCH_REGION,
        "type": SEARCH_TYPE,
        "capabilities": "VOICE",
        "size": str(SEARCH_SIZE),
        "numberPattern.pattern": SEARCH_AREA_CODE_E164,
        "numberPattern.searchPattern": "START",
    }
    log("search", "request", url=url, params=params)
    try:
        resp = client.get(url, params=params)
    except httpx.RequestError as err:
        log("search", "transport_error", error=repr(err))
        return None
    body = safe_json(resp)
    log("search", "response", http_status=resp.status_code, body=body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return None
    items = body.get("availableNumbers")
    if not isinstance(items, list) or not items:
        log("search", "no_inventory")
        return None
    candidate = items[0]
    if not isinstance(candidate, dict) or not isinstance(candidate.get("phoneNumber"), str):
        log("search", "malformed_candidate", candidate=candidate)
        return None
    log(
        "search",
        "selected",
        phoneNumber=candidate.get("phoneNumber"),
        setupPrice=candidate.get("setupPrice"),
        monthlyPrice=candidate.get("monthlyPrice"),
        supportingDocumentationRequired=candidate.get("supportingDocumentationRequired"),
    )
    return candidate


def rent_number(client: httpx.Client, cfg: ProbeConfig, phone: str) -> tuple[int, Any]:
    url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/availableNumbers/{phone}:rent"
    payload = {"voiceConfiguration": {"type": "RTC", "appId": SMITTY_VOICE_APP_ID}}
    log("rent", "request", url=url, body=payload, phone=phone)
    try:
        resp = client.post(url, json=payload)
    except httpx.RequestError as err:
        log("rent", "transport_error", phone=phone, error=repr(err))
        return -1, None
    body = safe_json(resp)
    log("rent", "response", phone=phone, http_status=resp.status_code, body=body)
    return resp.status_code, body


def release_number(client: httpx.Client, cfg: ProbeConfig, phone: str) -> int:
    url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/activeNumbers/{phone}:release"
    log("release", "request", url=url, phone=phone)
    try:
        resp = client.post(url)
    except httpx.RequestError as err:
        log("release", "transport_error", phone=phone, error=repr(err))
        return -1
    body = safe_json(resp)
    log("release", "response", phone=phone, http_status=resp.status_code, body=body)
    return resp.status_code


def release_with_retry(client: httpx.Client, cfg: ProbeConfig, phone: str) -> bool:
    code = release_number(client, cfg, phone)
    if 200 <= code < 300:
        return True
    log("release", "retry_after_delay", phone=phone, delay_sec=RELEASE_RETRY_DELAY_SEC)
    time.sleep(RELEASE_RETRY_DELAY_SEC)
    code = release_number(client, cfg, phone)
    return 200 <= code < 300


def run(cfg: ProbeConfig) -> int:
    log("probe", "start", project=cfg.project_id)
    token = exchange_token(cfg)
    if token is None:
        return 1
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Authorization": f"Bearer {token}",
    }
    with httpx.Client(timeout=30.0, headers=headers) as client:
        candidate = find_candidate(client, cfg)
        if candidate is None:
            log("probe", "no_candidate_aborting")
            return 1
        phone = candidate["phoneNumber"]
        rent_code, _ = rent_number(client, cfg, phone)
        if rent_code != 200 and rent_code != 201:
            log(
                "probe",
                "rent_refused",
                phone=phone,
                http_status=rent_code,
                interpretation="docs flag may gate rent OR config invalid OR insufficient permission",
            )
            log("probe", "clean_exit")
            return 0
        log("probe", "rent_succeeded_releasing_now", phone=phone)
        if release_with_retry(client, cfg, phone):
            log("probe", "release_succeeded")
            log("probe", "clean_exit")
            return 0
        log(
            "probe",
            "ORPHAN_NUMBER",
            phone=phone,
            severity="HIGH",
            action_required=(
                f"Manually release {phone} via Sinch dashboard "
                "(Numbers > Active numbers) or by calling the :release "
                "endpoint to stop billing."
            ),
        )
        return 2


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 6: rent + release a US LOCAL number")
    parser.add_argument("--numbers-api", default=NUMBERS_API_BASE)
    parser.add_argument("--auth-url", default=AUTH_TOKEN_URL)
    parser.add_argument("--yes", action="store_true", help="Skip interactive confirmation")
    args = parser.parse_args(argv)

    cfg = load_config(args)
    if cfg is None:
        return 1
    if not confirm(cfg):
        log("probe", "aborted_by_user")
        return 1
    return run(cfg)


if __name__ == "__main__":
    sys.exit(main())
