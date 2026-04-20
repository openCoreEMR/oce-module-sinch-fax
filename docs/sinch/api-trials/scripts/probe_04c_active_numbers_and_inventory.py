#!/usr/bin/env python3
"""Probe 4c for ADR-023: inspect active numbers + hunt for docs-free inventory.

Probe 4b found that 10/10 returned US LOCAL VOICE numbers had
`supportingDocumentationRequired: true`, yet the user's existing SmiTTY fax
number was rented via the dashboard without entering an E911 address or
uploading KYC documents. Two hypotheses remain:

  H1. Some US LOCAL VOICE inventory has `supportingDocumentationRequired:
      false`; 4b's sample happened to be all docs-required.
  H2. The dashboard performs a lightweight step the API caller must do
      explicitly (E911 via :emergencyAddress:provision, or pre-cached
      account-level KYC that isn't exposed to the API).

Both can be tested read-only:

  Phase A — Inspect the project's existing active numbers:
    * GET /v1/projects/{projectId}/activeNumbers
    * For each, GET /v1/projects/{projectId}/activeNumbers/{phoneNumber}
    * For each, GET .../emergencyAddress (may 404 — that's signal too)
  This tells us what a production-rented number's config looks like,
  including whether an emergency address is attached.

  Phase B — Hunt for docs-free availability:
    * Search several area codes + TOLL_FREE to find at least one number
      with `supportingDocumentationRequired: false`.
  Existence proof is enough; we don't need exhaustive counts.

Read-only throughout; no state is created. If an unexpected 5xx bubbles up
from a particular GET (e.g., number-without-emergency-address returns 500),
the probe logs it and continues rather than aborting the whole run.

Usage:
    export SINCH_PROJECT_ID=<your-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_04c_active_numbers_and_inventory.py

Exit codes:
    0 — all phases completed (findings may still be inconclusive)
    1 — config missing or token exchange failed
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass
from datetime import UTC, datetime
from typing import Any

import httpx

NUMBERS_API_BASE = "https://numbers.api.sinch.com"
AUTH_TOKEN_URL = "https://auth.sinch.com/oauth2/token"

# Area-code sweep. Full E.164 prefix is required as the pattern; searchPattern=START.
AREA_CODES_TO_TRY: tuple[tuple[str, str], ...] = (
    ("+1201", "NJ — where 4b found docs-required=true"),
    ("+1415", "SF"),
    ("+1312", "Chicago"),
    ("+1702", "Las Vegas"),
    ("+1307", "Wyoming (typically ample inventory)"),
)


@dataclass(frozen=True)
class ProbeConfig:
    project_id: str
    key_id: str
    key_secret: str
    numbers_api: str
    auth_url: str
    per_search_size: int
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
        per_search_size=args.per_search_size,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("ADR-023 Probe 4c: active numbers + docs-free inventory hunt", file=sys.stderr)
    print(f"  project     : {cfg.project_id}", file=sys.stderr)
    print(f"  key         : {cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print("  phase A     : list + inspect active numbers (incl. emergency address)", file=sys.stderr)
    print(f"  phase B     : search {len(AREA_CODES_TO_TRY)} area codes + TOLL_FREE", file=sys.stderr)
    print(f"                size limit per search = {cfg.per_search_size}", file=sys.stderr)
    print("  state       : none (read-only)", file=sys.stderr)
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


def phase_a_active_numbers(client: httpx.Client, cfg: ProbeConfig) -> None:
    log("phaseA", "begin")
    list_url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/activeNumbers"
    log("active_list", "request", url=list_url)
    try:
        resp = client.get(list_url)
    except httpx.RequestError as err:
        log("active_list", "transport_error", error=repr(err))
        return
    body = safe_json(resp)
    log("active_list", "response", http_status=resp.status_code, body=body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return
    active = body.get("activeNumbers") if isinstance(body.get("activeNumbers"), list) else []
    numbers = [n.get("phoneNumber") for n in active if isinstance(n, dict) and isinstance(n.get("phoneNumber"), str)]
    log("active_list", "summary", count=len(numbers), phone_numbers=numbers)

    for phone in numbers:
        detail_url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/activeNumbers/{phone}"
        log("active_detail", "request", url=detail_url, phone=phone)
        try:
            detail_resp = client.get(detail_url)
        except httpx.RequestError as err:
            log("active_detail", "transport_error", phone=phone, error=repr(err))
        else:
            log(
                "active_detail",
                "response",
                phone=phone,
                http_status=detail_resp.status_code,
                body=safe_json(detail_resp),
            )

        e911_url = f"{detail_url}/emergencyAddress"
        log("emergency_address", "request", url=e911_url, phone=phone)
        try:
            e911_resp = client.get(e911_url)
        except httpx.RequestError as err:
            log("emergency_address", "transport_error", phone=phone, error=repr(err))
            continue
        log(
            "emergency_address",
            "response",
            phone=phone,
            http_status=e911_resp.status_code,
            body=safe_json(e911_resp),
        )
    log("phaseA", "end")


def phase_b_availability_hunt(client: httpx.Client, cfg: ProbeConfig) -> None:
    log("phaseB", "begin")
    search_url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/availableNumbers"
    docs_free_found: list[dict[str, Any]] = []
    per_query_stats: list[dict[str, Any]] = []

    queries: list[tuple[str, dict[str, str]]] = []
    for prefix, note in AREA_CODES_TO_TRY:
        queries.append(
            (
                f"LOCAL {prefix} ({note})",
                {
                    "regionCode": "US",
                    "type": "LOCAL",
                    "capabilities": "VOICE",
                    "size": str(cfg.per_search_size),
                    "numberPattern.pattern": prefix,
                    "numberPattern.searchPattern": "START",
                },
            )
        )
    queries.append(
        (
            "US TOLL_FREE VOICE (any)",
            {
                "regionCode": "US",
                "type": "TOLL_FREE",
                "capabilities": "VOICE",
                "size": str(cfg.per_search_size),
            },
        )
    )

    for label, params in queries:
        log("search", "request", label=label, url=search_url, params=params)
        try:
            resp = client.get(search_url, params=params)
        except httpx.RequestError as err:
            log("search", "transport_error", label=label, error=repr(err))
            continue
        body = safe_json(resp)
        log("search", "response", label=label, http_status=resp.status_code, body=body)
        if resp.status_code != 200 or not isinstance(body, dict):
            per_query_stats.append({"label": label, "http_status": resp.status_code, "count": 0})
            continue
        numbers = body.get("availableNumbers")
        items = numbers if isinstance(numbers, list) else []
        docs_free = [
            n for n in items
            if isinstance(n, dict) and n.get("supportingDocumentationRequired") is False
        ]
        per_query_stats.append(
            {
                "label": label,
                "http_status": resp.status_code,
                "count": len(items),
                "docs_free_in_this_query": len(docs_free),
            }
        )
        if docs_free:
            sample = [
                {
                    "phoneNumber": n.get("phoneNumber"),
                    "type": n.get("type"),
                    "capability": n.get("capability"),
                    "supportingDocumentationRequired": n.get("supportingDocumentationRequired"),
                }
                for n in docs_free[:3]
            ]
            log("search", "docs_free_hits", label=label, count=len(docs_free), sample=sample)
            docs_free_found.extend(docs_free)

    log(
        "phaseB",
        "summary",
        total_docs_free_found=len(docs_free_found),
        first_docs_free=docs_free_found[0] if docs_free_found else None,
        per_query=per_query_stats,
    )
    log("phaseB", "end")


def run(cfg: ProbeConfig) -> int:
    log("probe", "start", project=cfg.project_id)
    token = exchange_token(cfg)
    if token is None:
        return 1
    headers = {"Accept": "application/json", "Authorization": f"Bearer {token}"}
    with httpx.Client(timeout=30.0, headers=headers) as client:
        phase_a_active_numbers(client, cfg)
        phase_b_availability_hunt(client, cfg)
    log("probe", "clean_exit")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 4c: active numbers + docs-free hunt")
    parser.add_argument("--numbers-api", default=NUMBERS_API_BASE)
    parser.add_argument("--auth-url", default=AUTH_TOKEN_URL)
    parser.add_argument("--per-search-size", type=int, default=10)
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
