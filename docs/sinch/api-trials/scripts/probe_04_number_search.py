#!/usr/bin/env python3
"""Probe 4 for ADR-023: Sinch available-numbers search (read-only).

Empirically verify the Numbers API search surface the provisioner will use
to present candidate DIDs to a human operator before the (out-of-scope-for-
this-probe) :rent call.

Confirmed from the canonical OpenAPI spec (via sinch-docs MCP):
  * capability enum is ["SMS", "VOICE"] — no FAX value. Fax == VOICE in
    production (confirmed by user from existing SmiTTY manual provisioning).
  * Response per-number includes `supportingDocumentationRequired` — when
    true, the number must go through the 48h :createNumberOrder + KYC flow
    instead of direct :rent.

Search:
  GET /v1/projects/{projectId}/availableNumbers
    ?regionCode=US
    &type=LOCAL
    &capabilities=VOICE
    [&numberPattern.pattern=<area-code>&numberPattern.searchPattern=START]
    &size=<N>

Read-only; no state to roll back.

Usage:
    export SINCH_PROJECT_ID=<your-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_04_number_search.py \\
        --area-code 212 \\
        --size 10

Exit codes:
    0 — search returned 200 (result list may be empty; that's still success)
    1 — config missing, aborted, or token exchange failed
    2 — search returned non-200
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


@dataclass(frozen=True)
class ProbeConfig:
    project_id: str
    key_id: str
    key_secret: str
    numbers_api: str
    auth_url: str
    region_code: str
    number_type: str
    area_code: str | None
    size: int
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
        region_code=args.region_code,
        number_type=args.number_type,
        area_code=args.area_code,
        size=args.size,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("ADR-023 Probe 4: available-numbers search (read-only)", file=sys.stderr)
    print(f"  project      : {cfg.project_id}", file=sys.stderr)
    print(f"  key          : {cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print(f"  region       : {cfg.region_code}", file=sys.stderr)
    print(f"  number type  : {cfg.number_type}", file=sys.stderr)
    print(f"  capability   : VOICE (== fax)", file=sys.stderr)
    print(f"  area code    : {cfg.area_code or '(any)'}", file=sys.stderr)
    print(f"  size limit   : {cfg.size}", file=sys.stderr)
    print("", file=sys.stderr)
    print("No state is created; read-only search.", file=sys.stderr)
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


def search_numbers(client: httpx.Client, cfg: ProbeConfig) -> httpx.Response | None:
    url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/availableNumbers"
    params: list[tuple[str, str]] = [
        ("regionCode", cfg.region_code),
        ("type", cfg.number_type),
        ("capabilities", "VOICE"),
        ("size", str(cfg.size)),
    ]
    if cfg.area_code is not None:
        params.append(("numberPattern.pattern", cfg.area_code))
        params.append(("numberPattern.searchPattern", "START"))
    log("search", "request", url=url, params=dict(params))
    try:
        resp = client.get(url, params=params)
    except httpx.RequestError as err:
        log("search", "transport_error", error=repr(err))
        return None
    return resp


def summarize_results(body: Any) -> dict[str, Any]:
    if not isinstance(body, dict):
        return {"shape": "unexpected"}
    numbers = body.get("availableNumbers")
    if not isinstance(numbers, list):
        return {"shape": "missing_availableNumbers", "keys": list(body.keys())}
    docs_required = sum(
        1 for n in numbers if isinstance(n, dict) and n.get("supportingDocumentationRequired") is True
    )
    direct_rent_eligible = len(numbers) - docs_required
    sample: list[dict[str, Any]] = []
    for n in numbers[:3]:
        if not isinstance(n, dict):
            continue
        sample.append(
            {
                "phoneNumber": n.get("phoneNumber"),
                "type": n.get("type"),
                "capability": n.get("capability"),
                "setupPrice": n.get("setupPrice"),
                "monthlyPrice": n.get("monthlyPrice"),
                "supportingDocumentationRequired": n.get("supportingDocumentationRequired"),
            }
        )
    return {
        "count": len(numbers),
        "docs_required_count": docs_required,
        "direct_rent_eligible_count": direct_rent_eligible,
        "sample_first_three": sample,
    }


def run(cfg: ProbeConfig) -> int:
    log("probe", "start", project=cfg.project_id)

    token = exchange_token(cfg)
    if token is None:
        return 1

    headers = {"Accept": "application/json", "Authorization": f"Bearer {token}"}
    with httpx.Client(timeout=30.0, headers=headers) as client:
        resp = search_numbers(client, cfg)
        if resp is None:
            return 2
        body = safe_json(resp)
        log("search", "response", http_status=resp.status_code, body=body)
        if resp.status_code != 200:
            return 2
        log("search", "summary", **summarize_results(body))

    log("probe", "clean_exit")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 4: Sinch available-numbers search")
    parser.add_argument("--numbers-api", default=NUMBERS_API_BASE)
    parser.add_argument("--auth-url", default=AUTH_TOKEN_URL)
    parser.add_argument("--region-code", default="US", help="ISO 3166-1 alpha-2 (e.g., US)")
    parser.add_argument("--number-type", default="LOCAL", choices=("MOBILE", "LOCAL", "TOLL_FREE"))
    parser.add_argument("--area-code", default=None, help="e.g., 212 for NYC — matched as START")
    parser.add_argument("--size", type=int, default=10)
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
