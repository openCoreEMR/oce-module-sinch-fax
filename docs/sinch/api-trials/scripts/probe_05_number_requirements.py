#!/usr/bin/env python3
"""Probe 5 for ADR-023: lookup US number-order requirements (read-only).

Probe 4b/4c established that 100% of sampled US LOCAL VOICE inventory
(and US TOLL_FREE VOICE) carries `supportingDocumentationRequired: true`,
which means the simple `:rent` call will not work. Numbers in that bucket
must instead go through the Number Order chain:

    lookupNumberRequirements -> availableNumbers -> createNumberOrder
    -> PUT registration -> [optional] attachments -> :submit

This probe runs the FIRST step of that chain, which is read-only and
returns the JSON Schema describing exactly what data US wants for each
number type the provisioner cares about (LOCAL and TOLL_FREE — the two
fax-capable types). From the response we can answer:

  * Are required fields data we already collect (business name + address
    + contact + use case)?  -> automation likely viable
  * Are mandatory attachments required (e.g., LoA file)?
    -> human-in-the-loop required
  * Are there free-text attestations that need human judgment?
    -> human-in-the-loop required

Endpoint:
    POST /v1/projects/{projectId}/numberOrders:lookupNumberRequirements
    body: {"regionCode": "US", "numberType": "LOCAL" | "TOLL_FREE"}

Read-only; no order is created.

Usage:
    export SINCH_PROJECT_ID=<your-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_05_number_requirements.py

Exit codes:
    0 — both lookups returned (any HTTP status; logged for analysis)
    1 — config missing, aborted, or token exchange failed
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

NUMBER_TYPES_TO_LOOKUP: tuple[str, ...] = ("LOCAL", "TOLL_FREE")
REGION_CODE = "US"


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
    print("ADR-023 Probe 5: lookup US number-order requirements (read-only)", file=sys.stderr)
    print(f"  project     : {cfg.project_id}", file=sys.stderr)
    print(f"  key         : {cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print(f"  region      : {REGION_CODE}", file=sys.stderr)
    print(f"  number types: {', '.join(NUMBER_TYPES_TO_LOOKUP)}", file=sys.stderr)
    print("  state       : none (no order is created)", file=sys.stderr)
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


def lookup_requirements(client: httpx.Client, cfg: ProbeConfig, number_type: str) -> None:
    url = f"{cfg.numbers_api}/v1/projects/{cfg.project_id}/numberOrders:lookupNumberRequirements"
    payload = {"regionCode": REGION_CODE, "numberType": number_type}
    log("lookup", "request", url=url, body=payload, number_type=number_type)
    try:
        resp = client.post(url, json=payload)
    except httpx.RequestError as err:
        log("lookup", "transport_error", number_type=number_type, error=repr(err))
        return
    body = safe_json(resp)
    log("lookup", "response", number_type=number_type, http_status=resp.status_code, body=body)


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
        for number_type in NUMBER_TYPES_TO_LOOKUP:
            lookup_requirements(client, cfg, number_type)
    log("probe", "clean_exit")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 5: lookup US number-order requirements")
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
