#!/usr/bin/env python3
"""Probe 2 for ADR-023: Sinch Access Key create/read/delete + one-shot secret.

Empirically verify the Access Key API surface the provisioner will depend on:

  * POST  /v1/projects/{projectId}/accessKeys         — create + return secret
  * GET   /v1/projects/{projectId}/accessKeys/{keyId} — confirms secret omitted
  * GET   /v1/projects/{projectId}/accessKeys         — key appears in list
  * POST  https://auth.sinch.com/oauth2/token         — new key is functional
  * DELETE /v1/projects/{projectId}/accessKeys/{keyId}

A fresh subproject wraps the flow so rollback is a clean DELETE pair with no
lingering state on the parent project. Orphan IDs are reported in the final
log record so a dashboard cleanup step is never ambiguous.

All Sinch calls use OAuth2 Bearer (ADR-023 Probe 1c established Basic is
unreliable for destructive verbs). The parent-project key mints the session
token; the newly created key mints a separate token used only for the
functional check.

Usage:
    export SINCH_PARENT_PROJECT_ID=<your-parent-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_02_access_key.py

Exit codes:
    0 — all steps succeeded, teardown clean
    1 — config missing or aborted
    2 — a step failed; one or more resources may be orphaned (see log)
"""

from __future__ import annotations

import argparse
import json
import os
import sys
import time
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any

import httpx

SUBPROJECT_API_BASE = "https://subproject.api.sinch.com"
ACCOUNT_API_BASE = "https://account.api.sinch.com"
AUTH_TOKEN_URL = "https://auth.sinch.com/oauth2/token"
SUBPROJECT_PROPAGATION_SECONDS = 35  # docs: "up to 30 second delay"


@dataclass(frozen=True)
class ProbeConfig:
    parent_project_id: str
    key_id: str
    key_secret: str
    subproject_api: str
    account_api: str
    auth_url: str
    propagation_sleep: int
    skip_prompt: bool


@dataclass
class ProbeState:
    subproject_id: str | None = None
    access_key_id: str | None = None
    access_key_secret: str | None = None
    orphans: list[tuple[str, str]] = field(default_factory=list)

    def mark_orphan(self, kind: str, identifier: str) -> None:
        self.orphans.append((kind, identifier))


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


def redact_secret_field(body: Any) -> Any:
    if not isinstance(body, dict):
        return body
    redacted = dict(body)
    if isinstance(redacted.get("secret"), str):
        redacted["secret"] = mask(redacted["secret"])
    if isinstance(redacted.get("access_token"), str):
        redacted["access_token"] = mask_token(redacted["access_token"])
    return redacted


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
        subproject_api=args.subproject_api,
        account_api=args.account_api,
        auth_url=args.auth_url,
        propagation_sleep=args.propagation_sleep,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("ADR-023 Probe 2: Access Key create/read/delete (production org)", file=sys.stderr)
    print(f"  parent project : {cfg.parent_project_id}", file=sys.stderr)
    print(f"  bootstrap key  : {cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print("", file=sys.stderr)
    print("Flow:", file=sys.stderr)
    print("  1. Create fresh subproject", file=sys.stderr)
    print(f"  2. Sleep {cfg.propagation_sleep}s for propagation", file=sys.stderr)
    print("  3. Create access key scoped to subproject", file=sys.stderr)
    print("  4. GET key — confirm secret omitted", file=sys.stderr)
    print("  5. LIST keys — confirm presence", file=sys.stderr)
    print("  6. Exchange new key for bearer token", file=sys.stderr)
    print("  7. DELETE key", file=sys.stderr)
    print("  8. DELETE subproject", file=sys.stderr)
    print("", file=sys.stderr)
    print("Proceed? [y/N] ", file=sys.stderr, end="", flush=True)
    return sys.stdin.readline().strip().lower() == "y"


def exchange_token(cfg: ProbeConfig, key_id: str, key_secret: str, *, label: str) -> str | None:
    log("token", "request", label=label, url=cfg.auth_url, key_id=key_id)
    auth = httpx.BasicAuth(username=key_id, password=key_secret)
    try:
        resp = httpx.post(
            cfg.auth_url,
            auth=auth,
            data={"grant_type": "client_credentials"},
            headers={"Accept": "application/json"},
            timeout=30.0,
        )
    except httpx.RequestError as err:
        log("token", "transport_error", label=label, error=repr(err))
        return None
    body = safe_json(resp)
    log("token", "response", label=label, http_status=resp.status_code, body=redact_secret_field(body))
    if resp.status_code != 200 or not isinstance(body, dict):
        return None
    token = body.get("access_token")
    return token if isinstance(token, str) and token else None


def create_subproject(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> bool:
    url = f"{cfg.subproject_api}/v1alpha1/projects/{cfg.parent_project_id}/subprojects"
    display_name = f"oce-probe02-{utc_stamp()}"
    payload = {"displayName": display_name}
    log("subproject_create", "request", url=url, body=payload)
    try:
        resp = client.post(url, json=payload)
    except httpx.RequestError as err:
        log("subproject_create", "transport_error", error=repr(err))
        return False
    body = safe_json(resp)
    log("subproject_create", "response", http_status=resp.status_code, body=body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return False
    subproject_id = body.get("subprojectId")
    if not isinstance(subproject_id, str):
        log("subproject_create", "malformed_response", note="missing subprojectId")
        return False
    state.subproject_id = subproject_id
    return True


def create_access_key(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> bool:
    assert state.subproject_id is not None
    url = f"{cfg.account_api}/v1/projects/{state.subproject_id}/accessKeys"
    display_name = f"oce-probe02-key-{utc_stamp()}"
    payload = {"displayName": display_name}
    log("key_create", "request", url=url, body=payload)
    try:
        resp = client.post(url, json=payload)
    except httpx.RequestError as err:
        log("key_create", "transport_error", error=repr(err))
        return False
    body = safe_json(resp)
    log("key_create", "response", http_status=resp.status_code, body=redact_secret_field(body))
    if resp.status_code != 200 or not isinstance(body, dict):
        return False
    access_key = body.get("accessKey")
    secret = body.get("secret")
    if not isinstance(access_key, dict) or not isinstance(secret, str):
        log("key_create", "malformed_response", keys_present=list(body.keys()))
        return False
    access_key_id = access_key.get("accessKeyId")
    if not isinstance(access_key_id, str):
        log("key_create", "malformed_response", note="missing accessKey.accessKeyId")
        return False
    state.access_key_id = access_key_id
    state.access_key_secret = secret
    log(
        "key_create",
        "captured",
        access_key_id=access_key_id,
        secret_len=len(secret),
        project_id_echoed=access_key.get("projectId"),
    )
    return True


def read_access_key(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> bool:
    assert state.subproject_id is not None and state.access_key_id is not None
    url = f"{cfg.account_api}/v1/projects/{state.subproject_id}/accessKeys/{state.access_key_id}"
    log("key_read", "request", url=url)
    try:
        resp = client.get(url)
    except httpx.RequestError as err:
        log("key_read", "transport_error", error=repr(err))
        return False
    body = safe_json(resp)
    log("key_read", "response", http_status=resp.status_code, body=redact_secret_field(body))
    if resp.status_code != 200 or not isinstance(body, dict):
        return False
    secret_present = "secret" in body or (isinstance(body.get("accessKey"), dict) and "secret" in body["accessKey"])
    log("key_read", "one_shot_check", secret_present_on_get=secret_present)
    return True


def list_access_keys(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> bool:
    assert state.subproject_id is not None and state.access_key_id is not None
    url = f"{cfg.account_api}/v1/projects/{state.subproject_id}/accessKeys"
    log("key_list", "request", url=url)
    try:
        resp = client.get(url)
    except httpx.RequestError as err:
        log("key_list", "transport_error", error=repr(err))
        return False
    body = safe_json(resp)
    log("key_list", "response", http_status=resp.status_code, body=body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return False
    keys = body.get("accessKeys")
    if not isinstance(keys, list):
        log("key_list", "malformed_response", keys_present=list(body.keys()))
        return False
    found = any(
        isinstance(k, dict) and k.get("accessKeyId") == state.access_key_id for k in keys
    )
    log("key_list", "presence_check", new_key_found=found, total=len(keys))
    return found


def functional_check(cfg: ProbeConfig, state: ProbeState) -> bool:
    assert state.access_key_id is not None and state.access_key_secret is not None
    token = exchange_token(cfg, state.access_key_id, state.access_key_secret, label="new_key")
    if token is None:
        log("functional", "token_exchange_failed")
        return False
    log("functional", "success", note="new key mints bearer tokens")
    return True


def delete_access_key(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> bool:
    assert state.subproject_id is not None and state.access_key_id is not None
    url = f"{cfg.account_api}/v1/projects/{state.subproject_id}/accessKeys/{state.access_key_id}"
    log("key_delete", "request", url=url)
    try:
        resp = client.delete(url)
    except httpx.RequestError as err:
        log("key_delete", "transport_error", error=repr(err))
        state.mark_orphan("access_key", f"{state.subproject_id}/{state.access_key_id}")
        return False
    log("key_delete", "response", http_status=resp.status_code, body=safe_json(resp))
    if resp.status_code not in (200, 204):
        state.mark_orphan("access_key", f"{state.subproject_id}/{state.access_key_id}")
        return False
    return True


def delete_subproject(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> bool:
    assert state.subproject_id is not None
    url = f"{cfg.subproject_api}/v1alpha1/subprojects/{state.subproject_id}"
    log("subproject_delete", "request", url=url)
    try:
        resp = client.delete(url)
    except httpx.RequestError as err:
        log("subproject_delete", "transport_error", error=repr(err))
        state.mark_orphan("subproject", state.subproject_id)
        return False
    log("subproject_delete", "response", http_status=resp.status_code, body=safe_json(resp))
    if resp.status_code not in (200, 204):
        state.mark_orphan("subproject", state.subproject_id)
        return False
    return True


def teardown(client: httpx.Client, cfg: ProbeConfig, state: ProbeState) -> None:
    log("teardown", "begin", subproject_id=state.subproject_id, access_key_id=state.access_key_id)
    if state.access_key_id is not None:
        delete_access_key(client, cfg, state)
    if state.subproject_id is not None:
        delete_subproject(client, cfg, state)
    log("teardown", "end", orphans=state.orphans)


def run(cfg: ProbeConfig) -> int:
    log("probe", "start", parent=cfg.parent_project_id)

    session_token = exchange_token(cfg, cfg.key_id, cfg.key_secret, label="parent_key")
    if session_token is None:
        log("probe", "session_token_failed")
        return 1

    state = ProbeState()
    headers = {"Accept": "application/json", "Authorization": f"Bearer {session_token}"}
    outcome = 2
    with httpx.Client(timeout=30.0, headers=headers) as client:
        try:
            if not create_subproject(client, cfg, state):
                log("probe", "abort", stage="subproject_create")
                return 2

            log("probe", "sleep_for_propagation", seconds=cfg.propagation_sleep)
            time.sleep(cfg.propagation_sleep)

            if not create_access_key(client, cfg, state):
                log("probe", "abort", stage="key_create")
                return 2
            if not read_access_key(client, cfg, state):
                log("probe", "abort", stage="key_read")
                return 2
            if not list_access_keys(client, cfg, state):
                log("probe", "abort", stage="key_list")
                return 2
            if not functional_check(cfg, state):
                log("probe", "abort", stage="functional_check")
                return 2

            log("probe", "all_steps_passed")
            outcome = 0
        finally:
            teardown(client, cfg, state)

    if state.orphans:
        log("probe", "orphans_present", orphans=state.orphans)
        return 2
    log("probe", "clean_exit", status_code=outcome)
    return outcome


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 2: Sinch access key lifecycle")
    parser.add_argument("--subproject-api", default=SUBPROJECT_API_BASE)
    parser.add_argument("--account-api", default=ACCOUNT_API_BASE)
    parser.add_argument("--auth-url", default=AUTH_TOKEN_URL)
    parser.add_argument("--propagation-sleep", type=int, default=SUBPROJECT_PROPAGATION_SECONDS)
    parser.add_argument("--yes", action="store_true", help="Skip the interactive confirmation prompt")
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
