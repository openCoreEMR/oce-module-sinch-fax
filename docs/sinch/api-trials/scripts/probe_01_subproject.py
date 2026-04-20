#!/usr/bin/env python3
"""Probe 1 for ADR-023: Sinch Subproject create + delete.

Answers empirically (not from docs):
  1. Does `POST /v1alpha1/projects/{parent}/subprojects` with a project-scoped
     access key succeed against the OpenCoreEMR Inc parent project, or does
     creating subprojects require a key with different scope/role?
  2. What is the exact request schema? We try a minimal body first; if the
     server rejects it with 400, the error message usually documents the
     required fields.
  3. What is the response schema? Specifically, what identifier do we need
     to capture for subsequent calls?
  4. Does `DELETE /v1alpha1/subprojects/{id}` actually tear down the
     subproject, or only soft-delete / refuse?

Non-goals: access keys, fax services, numbers. Those are later probes.

Usage:
    export SINCH_PARENT_PROJECT_ID=<your-parent-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read 'op://OCE Dev Tenant Systems/Sinch Keys/add more/SmiTTY Test Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read 'op://OCE Dev Tenant Systems/Sinch Keys/add more/SmiTTY Test Key Secret')"
    python scripts/sinch/probe_01_subproject.py          # prints plan, prompts before mutating
    python scripts/sinch/probe_01_subproject.py --yes    # skips the confirmation prompt

Exit codes:
    0 — probe ran cleanly, subproject created and deleted
    1 — probe could not run (missing env, auth rejected, create failed)
    2 — WARNING: subproject created but cleanup failed; orphaned resource reported

Reads secrets from env only. Never accepts secrets as argv. Never logs the
access key secret; logs the access key ID (already in 1P by label) and a
masked fingerprint of the secret so correlation is possible without leak.
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
PROBE_NAME_PREFIX = "oce-probe-adr023"


@dataclass(frozen=True)
class ProbeConfig:
    parent_project_id: str
    key_id: str
    key_secret: str
    api_base: str
    skip_prompt: bool


def utc_stamp() -> str:
    return datetime.now(tz=UTC).strftime("%Y%m%dT%H%M%SZ")


def mask(secret: str) -> str:
    if len(secret) < 8:
        return "****"
    return f"{secret[:2]}…{secret[-2:]} (len={len(secret)})"


def log(phase: str, status: str, **extra: Any) -> None:
    record = {"ts": utc_stamp(), "phase": phase, "status": status, **extra}
    print(json.dumps(record, default=str), flush=True)


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
        api_base=args.api_base,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig, subproject_name: str) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("About to call Sinch Subproject API (production org):", file=sys.stderr)
    print(f"  POST   {cfg.api_base}/v1alpha1/projects/{cfg.parent_project_id}/subprojects", file=sys.stderr)
    print(f"  body   {{\"displayName\": {subproject_name!r}}}  (minimal body — required fields unknown)", file=sys.stderr)
    print(f"  auth   Basic  key_id={cfg.key_id}  secret={mask(cfg.key_secret)}", file=sys.stderr)
    print("", file=sys.stderr)
    print("On success the probe will immediately DELETE the subproject.", file=sys.stderr)
    print("Proceed? [y/N] ", file=sys.stderr, end="", flush=True)
    answer = sys.stdin.readline().strip().lower()
    return answer == "y"


def create_subproject(client: httpx.Client, cfg: ProbeConfig, display_name: str) -> dict[str, Any] | None:
    url = f"{cfg.api_base}/v1alpha1/projects/{cfg.parent_project_id}/subprojects"
    body = {"displayName": display_name}
    log("create", "request", url=url, body=body)
    try:
        resp = client.post(url, json=body)
    except httpx.RequestError as err:
        log("create", "transport_error", error=repr(err))
        return None
    log(
        "create",
        "response",
        http_status=resp.status_code,
        headers={k: v for k, v in resp.headers.items() if k.lower() in {"content-type", "x-request-id"}},
        body=safe_json(resp),
    )
    if resp.status_code not in (200, 201):
        return None
    return safe_json(resp) if isinstance(safe_json(resp), dict) else None


def delete_subproject(client: httpx.Client, cfg: ProbeConfig, subproject_id: str) -> bool:
    url = f"{cfg.api_base}/v1alpha1/subprojects/{subproject_id}"
    log("delete", "request", url=url)
    try:
        resp = client.delete(url)
    except httpx.RequestError as err:
        log("delete", "transport_error", error=repr(err))
        return False
    log("delete", "response", http_status=resp.status_code, body=safe_json(resp))
    return resp.status_code in (200, 204)


def safe_json(resp: httpx.Response) -> Any:
    try:
        return resp.json()
    except ValueError:
        return resp.text[:2000]


def extract_subproject_id(response_body: dict[str, Any]) -> str | None:
    for key in ("id", "subprojectId", "name"):
        value = response_body.get(key)
        if isinstance(value, str) and value:
            if "/" in value:
                return value.rsplit("/", 1)[-1]
            return value
    return None


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 1: Sinch subproject create + delete")
    parser.add_argument("--api-base", default=SUBPROJECT_API_BASE, help="Override Sinch subproject API base URL")
    parser.add_argument("--yes", action="store_true", help="Skip the interactive confirmation prompt")
    args = parser.parse_args(argv)

    cfg = load_config(args)
    if cfg is None:
        return 1

    display_name = f"{PROBE_NAME_PREFIX}-{utc_stamp()}"
    if not confirm(cfg, display_name):
        log("probe", "aborted_by_user")
        return 1

    auth = httpx.BasicAuth(username=cfg.key_id, password=cfg.key_secret)
    with httpx.Client(auth=auth, timeout=30.0, headers={"Accept": "application/json"}) as client:
        log("probe", "start", display_name=display_name, parent_project_id=cfg.parent_project_id)

        created = create_subproject(client, cfg, display_name)
        if created is None:
            log("probe", "create_failed")
            return 1

        subproject_id = extract_subproject_id(created)
        if not subproject_id:
            log("probe", "id_extraction_failed", body=created)
            log("probe", "ORPHANED", note="subproject may have been created but ID could not be extracted")
            return 2

        log("probe", "created", subproject_id=subproject_id)

        if not delete_subproject(client, cfg, subproject_id):
            log(
                "probe",
                "ORPHANED",
                subproject_id=subproject_id,
                note=f"manual cleanup required: DELETE {cfg.api_base}/v1alpha1/subprojects/{subproject_id}",
            )
            return 2

        log("probe", "done", subproject_id=subproject_id)
        return 0


if __name__ == "__main__":
    sys.exit(main())
