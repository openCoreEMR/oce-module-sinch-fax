#!/usr/bin/env python3
"""Probe 1c for ADR-023: rule out auth-scheme as the cause of the DELETE 403.

Probe 1 established that Basic auth with the SmiTTY Fax Key (parent-project
scoped) succeeds on POST create but returns 403 PERMISSION_DENIED on
DELETE /v1alpha1/subprojects/{id}. Sinch's API-reference overview documents
both Basic and OAuth2 bearer as valid auth schemes for the Subprojects API,
noting Basic is "for testing/prototyping" and Bearer is recommended for prod.

A bearer token minted from the same Key ID + Key Secret represents the same
principal with the same roles, so the expectation is that DELETE will still
403. This probe exists to rule out a server-side quirk where Basic is
accepted for some verbs but not others. If the bearer-token DELETE still
403s, the blocker is authority on the key, not the auth scheme.

Steps:
  1. POST https://auth.sinch.com/oauth2/token
     (client_credentials grant, Basic auth with key_id/secret)
  2. DELETE https://subproject.api.sinch.com/v1alpha1/subprojects/{id}
     with Authorization: Bearer <access_token>
  3. GET the subproject and report whether `deleted` flipped.

Usage:
    export SINCH_PARENT_PROJECT_ID=<your-parent-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_01c_bearer_delete.py --subproject-id <uuid>

Exit codes:
    0 — DELETE returned 2xx and GET confirms deletion
    1 — config missing, token exchange failed, or probe aborted
    2 — DELETE did not succeed (authority problem confirmed across schemes)
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

SUBPROJECT_API_BASE = "https://subproject.api.sinch.com"
AUTH_TOKEN_URL = "https://auth.sinch.com/oauth2/token"


@dataclass(frozen=True)
class ProbeConfig:
    parent_project_id: str
    key_id: str
    key_secret: str
    subproject_id: str
    api_base: str
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


def load_config(args: argparse.Namespace) -> ProbeConfig | None:
    missing = [
        var
        for var in ("SINCH_PARENT_PROJECT_ID", "SINCH_ACCESS_KEY_ID", "SINCH_ACCESS_KEY_SECRET")
        if not os.environ.get(var)
    ]
    if missing:
        log("config", "missing_env", variables=missing)
        return None
    return ProbeConfig(
        parent_project_id=os.environ["SINCH_PARENT_PROJECT_ID"],
        key_id=os.environ["SINCH_ACCESS_KEY_ID"],
        key_secret=os.environ["SINCH_ACCESS_KEY_SECRET"],
        subproject_id=args.subproject_id,
        api_base=args.api_base,
        auth_url=args.auth_url,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("About to call Sinch APIs (production org).", file=sys.stderr)
    print(f"  target subproject : {cfg.subproject_id}", file=sys.stderr)
    print(f"  parent project    : {cfg.parent_project_id}", file=sys.stderr)
    print(f"  auth              : Basic key_id={cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print("", file=sys.stderr)
    print("Steps:", file=sys.stderr)
    print(f"  1) POST   {cfg.auth_url}  (client_credentials grant)", file=sys.stderr)
    print(f"  2) DELETE {cfg.api_base}/v1alpha1/subprojects/{cfg.subproject_id}  (Bearer)", file=sys.stderr)
    print(f"  3) GET    {cfg.api_base}/v1alpha1/subprojects/{cfg.subproject_id}  (Bearer)", file=sys.stderr)
    print("", file=sys.stderr)
    print("Proceed? [y/N] ", file=sys.stderr, end="", flush=True)
    return sys.stdin.readline().strip().lower() == "y"


def exchange_token(cfg: ProbeConfig) -> str | None:
    log("token", "request", url=cfg.auth_url, grant_type="client_credentials")
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
    logged_body: Any
    if isinstance(body, dict) and isinstance(body.get("access_token"), str):
        logged_body = {
            **{k: v for k, v in body.items() if k != "access_token"},
            "access_token": mask_token(body["access_token"]),
        }
    else:
        logged_body = body
    log("token", "response", http_status=resp.status_code, body=logged_body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return None
    token = body.get("access_token")
    return token if isinstance(token, str) and token else None


def get_subproject(client: httpx.Client, cfg: ProbeConfig) -> dict[str, Any] | None:
    url = f"{cfg.api_base}/v1alpha1/subprojects/{cfg.subproject_id}"
    log("verify", "request", method="GET", url=url)
    try:
        resp = client.get(url)
    except httpx.RequestError as err:
        log("verify", "transport_error", error=repr(err))
        return None
    body = safe_json(resp)
    log("verify", "response", http_status=resp.status_code, body=body)
    return body if resp.status_code == 200 and isinstance(body, dict) else None


def is_deleted(subproject_body: dict[str, Any] | None) -> bool:
    return isinstance(subproject_body, dict) and subproject_body.get("deleted") is True


def delete_subproject(client: httpx.Client, cfg: ProbeConfig) -> bool:
    url = f"{cfg.api_base}/v1alpha1/subprojects/{cfg.subproject_id}"
    log("delete", "request", method="DELETE", url=url)
    try:
        resp = client.delete(url)
    except httpx.RequestError as err:
        log("delete", "transport_error", error=repr(err))
        return False
    log("delete", "response", http_status=resp.status_code, body=safe_json(resp))
    return resp.status_code in (200, 204)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 1c: Sinch subproject DELETE with bearer token")
    parser.add_argument("--subproject-id", required=True, help="UUID of the subproject to tear down")
    parser.add_argument("--api-base", default=SUBPROJECT_API_BASE, help="Override Sinch subproject API base URL")
    parser.add_argument("--auth-url", default=AUTH_TOKEN_URL, help="Override Sinch OAuth2 token URL")
    parser.add_argument("--yes", action="store_true", help="Skip the interactive confirmation prompt")
    args = parser.parse_args(argv)

    cfg = load_config(args)
    if cfg is None:
        return 1
    if not confirm(cfg):
        log("probe", "aborted_by_user")
        return 1

    log("probe", "start", subproject_id=cfg.subproject_id)

    token = exchange_token(cfg)
    if token is None:
        log("probe", "token_exchange_failed")
        return 1

    headers = {"Accept": "application/json", "Authorization": f"Bearer {token}"}
    with httpx.Client(timeout=30.0, headers=headers) as client:
        initial = get_subproject(client, cfg)
        if initial is None:
            log("probe", "pre_check_failed", note="cannot read subproject — wrong ID or token rejected")
            return 1
        if is_deleted(initial):
            log("probe", "already_deleted", body=initial)
            return 0

        if not delete_subproject(client, cfg):
            log(
                "probe",
                "delete_rejected",
                note="authority problem confirmed: bearer and basic both fail on this key",
            )
            return 2

        after = get_subproject(client, cfg)
        if is_deleted(after) or after is None:
            log("probe", "success", method="bearer_delete")
            return 0
        log("probe", "accepted_but_not_reflected", body=after)
        return 2


if __name__ == "__main__":
    sys.exit(main())
