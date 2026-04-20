#!/usr/bin/env python3
"""Probe 1b for ADR-023: determine the correct subproject teardown mechanism.

Probe 1 established that `POST /v1alpha1/projects/{parent}/subprojects` with a
parent-project-scoped access key succeeds (200), but the same key calling
`DELETE /v1alpha1/subprojects/{id}` returns 403 PERMISSION_DENIED. Either
DELETE is the wrong verb, the path is wrong, or teardown needs a different
role even at parent scope.

This probe runs two candidate teardown calls against an existing subproject,
stopping on the first success. Between attempts it GETs the subproject to
see whether `deleted` has flipped to true (soft-delete semantics). Candidates:

  1. PATCH /v1alpha1/subprojects/{id}?updateMask=deleted  {"deleted": true}
     — motivated by the `deleted` bool in the create response + Google-AIP
       naming style (v1alpha1, parentProjectId). Query param is camelCase
       per standard gRPC-to-REST transcoding convention. (A previous run
       with snake_case `update_mask` returned 400 with a marshaling error,
       suggesting the field mask format itself was rejected.)

  2. DELETE /v1alpha1/projects/{parentId}/subprojects/{id}
     — maybe teardown requires the parent-scoped path rather than the
       direct resource path.

Non-goals: trying random verbs, trying other HTTP auth modes, trying every
path variant. If neither candidate works we stop and ask Sinch support
rather than guessing further.

Usage:
    export SINCH_PARENT_PROJECT_ID=<your-parent-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read 'op://OCE Dev Tenant Systems/Sinch Keys/add more/SmiTTY Test Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read 'op://OCE Dev Tenant Systems/Sinch Keys/add more/SmiTTY Test Key Secret')"
    python scripts/sinch/probe_01b_subproject_delete.py --subproject-id <subproject-id>

Exit codes:
    0 — a candidate teardown call succeeded and GET confirms deletion
    1 — config missing, bad subproject ID, or probe rejected
    2 — all candidate teardown calls exhausted; subproject still live
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


@dataclass(frozen=True)
class ProbeConfig:
    parent_project_id: str
    key_id: str
    key_secret: str
    subproject_id: str
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
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("About to call Sinch Subproject API (production org).", file=sys.stderr)
    print(f"  target subproject : {cfg.subproject_id}", file=sys.stderr)
    print(f"  parent project    : {cfg.parent_project_id}", file=sys.stderr)
    print(f"  auth              : Basic key_id={cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print("", file=sys.stderr)
    print("Attempts (stop on first success):", file=sys.stderr)
    print(f"  1) PATCH  {cfg.api_base}/v1alpha1/subprojects/{cfg.subproject_id}?updateMask=deleted  {{'deleted': true}}", file=sys.stderr)
    print(f"  2) DELETE {cfg.api_base}/v1alpha1/projects/{cfg.parent_project_id}/subprojects/{cfg.subproject_id}", file=sys.stderr)
    print("", file=sys.stderr)
    print("Proceed? [y/N] ", file=sys.stderr, end="", flush=True)
    return sys.stdin.readline().strip().lower() == "y"


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


def attempt_patch_soft_delete(client: httpx.Client, cfg: ProbeConfig) -> bool:
    url = f"{cfg.api_base}/v1alpha1/subprojects/{cfg.subproject_id}"
    params = {"updateMask": "deleted"}
    body = {"deleted": True}
    log("attempt_1", "request", method="PATCH", url=url, params=params, body=body)
    try:
        resp = client.patch(url, params=params, json=body)
    except httpx.RequestError as err:
        log("attempt_1", "transport_error", error=repr(err))
        return False
    log("attempt_1", "response", http_status=resp.status_code, body=safe_json(resp))
    return resp.status_code in (200, 204)


def attempt_delete_via_parent_path(client: httpx.Client, cfg: ProbeConfig) -> bool:
    url = f"{cfg.api_base}/v1alpha1/projects/{cfg.parent_project_id}/subprojects/{cfg.subproject_id}"
    log("attempt_2", "request", method="DELETE", url=url)
    try:
        resp = client.delete(url)
    except httpx.RequestError as err:
        log("attempt_2", "transport_error", error=repr(err))
        return False
    log("attempt_2", "response", http_status=resp.status_code, body=safe_json(resp))
    return resp.status_code in (200, 204)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 1b: Sinch subproject teardown methods")
    parser.add_argument("--subproject-id", required=True, help="UUID of the subproject to tear down")
    parser.add_argument("--api-base", default=SUBPROJECT_API_BASE, help="Override Sinch subproject API base URL")
    parser.add_argument("--yes", action="store_true", help="Skip the interactive confirmation prompt")
    args = parser.parse_args(argv)

    cfg = load_config(args)
    if cfg is None:
        return 1
    if not confirm(cfg):
        log("probe", "aborted_by_user")
        return 1

    auth = httpx.BasicAuth(username=cfg.key_id, password=cfg.key_secret)
    with httpx.Client(auth=auth, timeout=30.0, headers={"Accept": "application/json"}) as client:
        log("probe", "start", subproject_id=cfg.subproject_id)

        initial = get_subproject(client, cfg)
        if initial is None:
            log("probe", "pre_check_failed", note="cannot read subproject — wrong ID or permission issue")
            return 1
        if is_deleted(initial):
            log("probe", "already_deleted", body=initial)
            return 0

        if attempt_patch_soft_delete(client, cfg):
            after = get_subproject(client, cfg)
            if is_deleted(after):
                log("probe", "success", method="patch_soft_delete", body=after)
                return 0
            log("attempt_1", "accepted_but_not_reflected", body=after)

        if attempt_delete_via_parent_path(client, cfg):
            after = get_subproject(client, cfg)
            if after is None or is_deleted(after):
                log("probe", "success", method="delete_via_parent_path")
                return 0
            log("attempt_2", "accepted_but_not_reflected", body=after)

        log("probe", "exhausted", note="neither candidate teardown succeeded; manual cleanup required")
        return 2


if __name__ == "__main__":
    sys.exit(main())
