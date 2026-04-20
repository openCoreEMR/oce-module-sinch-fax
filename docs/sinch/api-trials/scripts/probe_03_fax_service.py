#!/usr/bin/env python3
"""Probe 3 for ADR-023: Sinch Fax Service CRUD + embedded-creds webhook URL.

Empirically verify the Fax Service API surface the provisioner will depend on,
under the interim assumption that all tenants share a single project and
each tenant gets its own fax service (Probe 2 showed that a key scoped to
one project cannot mint access keys in another project; per-tenant project
scoping is deferred).

Steps (all OAuth2 Bearer, one session token):
  1. LIST services — discover correct base path and snapshot existing IDs.
  2. CREATE a probe-named service with credentials embedded in the webhook URL.
  3. GET — confirm the URL is echoed verbatim (tells us whether creds leak on read).
  4. PATCH — change name + URL.
  5. GET — confirm PATCH took effect.
  6. LIST — confirm our service is present.
  7. DELETE.
  8. LIST — confirm gone.

Safety: the probe never touches services it didn't create. The post-LIST
snapshot is the authoritative set of "existing" services; teardown targets
only the service this run created.

Base-path discovery: tries /v3/projects/{projectId}/services first, falls
back to /services. The working form is recorded in the log.

Usage:
    export SINCH_PROJECT_ID=<your-project-id>
    export SINCH_ACCESS_KEY_ID="$(op read '...Key Id')"
    export SINCH_ACCESS_KEY_SECRET="$(op read '...Key Secret')"
    python scripts/sinch/probe_03_fax_service.py

Exit codes:
    0 — all steps succeeded, teardown clean
    1 — config missing or aborted
    2 — a step failed; the final log record lists any orphaned service
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass, field
from datetime import UTC, datetime
from typing import Any

import httpx

FAX_API_BASE = "https://fax.api.sinch.com"
AUTH_TOKEN_URL = "https://auth.sinch.com/oauth2/token"


@dataclass(frozen=True)
class ProbeConfig:
    project_id: str
    key_id: str
    key_secret: str
    fax_api: str
    auth_url: str
    skip_prompt: bool


@dataclass
class ProbeState:
    services_base: str | None = None  # URL prefix up to and including "/services"
    service_id: str | None = None
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
        fax_api=args.fax_api,
        auth_url=args.auth_url,
        skip_prompt=args.yes,
    )


def confirm(cfg: ProbeConfig) -> bool:
    if cfg.skip_prompt:
        return True
    print("", file=sys.stderr)
    print("ADR-023 Probe 3: Fax Service CRUD (production org)", file=sys.stderr)
    print(f"  project      : {cfg.project_id}", file=sys.stderr)
    print(f"  key          : {cfg.key_id} secret={mask(cfg.key_secret)}", file=sys.stderr)
    print(f"  fax api base : {cfg.fax_api}", file=sys.stderr)
    print("", file=sys.stderr)
    print("Flow: list → create (oce-probe03-…) → get → patch → get → list → delete → list", file=sys.stderr)
    print("Created service has webhook URL pointing at example.invalid — no real traffic.", file=sys.stderr)
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


def candidate_bases(cfg: ProbeConfig) -> list[str]:
    return [
        f"{cfg.fax_api}/v3/projects/{cfg.project_id}/services",
        f"{cfg.fax_api}/services",
    ]





def discover_services_base(client: httpx.Client, cfg: ProbeConfig) -> str | None:
    for candidate in candidate_bases(cfg):
        log("discover", "try", url=candidate, method="GET")
        try:
            resp = client.get(candidate)
        except httpx.RequestError as err:
            log("discover", "transport_error", url=candidate, error=repr(err))
            continue
        log("discover", "result", url=candidate, http_status=resp.status_code)
        if resp.status_code == 200:
            log("discover", "selected", base=candidate)
            return candidate
    log("discover", "exhausted")
    return None


def list_services(client: httpx.Client, state: ProbeState, *, label: str) -> list[dict[str, Any]] | None:
    assert state.services_base is not None
    log("list", "request", label=label, url=state.services_base)
    try:
        resp = client.get(state.services_base)
    except httpx.RequestError as err:
        log("list", "transport_error", label=label, error=repr(err))
        return None
    body = safe_json(resp)
    log("list", "response", label=label, http_status=resp.status_code, body=body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return None
    services = body.get("services")
    if isinstance(services, list):
        return [s for s in services if isinstance(s, dict)]
    if isinstance(body, list):
        return [s for s in body if isinstance(s, dict)]  # type: ignore[unreachable]
    log("list", "unexpected_shape", keys=list(body.keys()))
    return None


def create_service(client: httpx.Client, state: ProbeState) -> dict[str, Any] | None:
    assert state.services_base is not None
    stamp = utc_stamp()
    name = f"oce-probe03-{stamp}"
    webhook_url = f"https://probeuser-{stamp}:probepass-{stamp}@example.invalid/fax-callback"
    payload = {
        "name": name,
        "incomingWebhookUrl": webhook_url,
        "webhookContentType": "application/json",
        "numberOfRetries": 3,
    }
    log("create", "request", url=state.services_base, body=payload)
    try:
        resp = client.post(state.services_base, json=payload)
    except httpx.RequestError as err:
        log("create", "transport_error", error=repr(err))
        return None
    body = safe_json(resp)
    log("create", "response", http_status=resp.status_code, body=body)
    if resp.status_code not in (200, 201) or not isinstance(body, dict):
        return None
    service_id = body.get("id")
    if not isinstance(service_id, str):
        log("create", "malformed_response", keys=list(body.keys()))
        return None
    state.service_id = service_id
    url_echoed = body.get("incomingWebhookUrl")
    creds_visible_on_create = isinstance(url_echoed, str) and "probepass-" in url_echoed
    log(
        "create",
        "captured",
        service_id=service_id,
        name_echoed=body.get("name"),
        webhook_url_echoed=url_echoed,
        creds_visible_on_create=creds_visible_on_create,
    )
    return body


def get_service(client: httpx.Client, state: ProbeState, *, label: str) -> dict[str, Any] | None:
    assert state.services_base is not None and state.service_id is not None
    url = f"{state.services_base}/{state.service_id}"
    log("get", "request", label=label, url=url)
    try:
        resp = client.get(url)
    except httpx.RequestError as err:
        log("get", "transport_error", label=label, error=repr(err))
        return None
    body = safe_json(resp)
    log("get", "response", label=label, http_status=resp.status_code, body=body)
    if resp.status_code != 200 or not isinstance(body, dict):
        return None
    webhook_echoed = body.get("incomingWebhookUrl")
    creds_visible_on_get = isinstance(webhook_echoed, str) and "probepass-" in webhook_echoed
    log(
        "get",
        "inspection",
        label=label,
        webhook_url_echoed=webhook_echoed,
        creds_visible_on_get=creds_visible_on_get,
    )
    return body


def patch_service(client: httpx.Client, state: ProbeState) -> bool:
    assert state.services_base is not None and state.service_id is not None
    url = f"{state.services_base}/{state.service_id}"
    stamp = utc_stamp()
    new_name = f"oce-probe03-patched-{stamp}"
    new_webhook = f"https://patched-{stamp}:newpass-{stamp}@example.invalid/fax-callback-v2"
    payload = {
        "name": new_name,
        "incomingWebhookUrl": new_webhook,
    }
    log("patch", "request", url=url, body=payload)
    try:
        resp = client.patch(url, json=payload)
    except httpx.RequestError as err:
        log("patch", "transport_error", error=repr(err))
        return False
    body = safe_json(resp)
    log("patch", "response", http_status=resp.status_code, body=body)
    return resp.status_code in (200, 204)


def delete_service(client: httpx.Client, state: ProbeState) -> bool:
    assert state.services_base is not None and state.service_id is not None
    url = f"{state.services_base}/{state.service_id}"
    log("delete", "request", url=url)
    try:
        resp = client.delete(url)
    except httpx.RequestError as err:
        log("delete", "transport_error", error=repr(err))
        state.mark_orphan("fax_service", state.service_id)
        return False
    log("delete", "response", http_status=resp.status_code, body=safe_json(resp))
    if resp.status_code not in (200, 204):
        state.mark_orphan("fax_service", state.service_id)
        return False
    return True


def teardown(client: httpx.Client, state: ProbeState) -> None:
    log("teardown", "begin", service_id=state.service_id)
    if state.service_id is not None:
        delete_service(client, state)
    log("teardown", "end", orphans=state.orphans)


def run(cfg: ProbeConfig) -> int:
    log("probe", "start", project=cfg.project_id)

    token = exchange_token(cfg)
    if token is None:
        return 1

    state = ProbeState()
    headers = {"Accept": "application/json", "Authorization": f"Bearer {token}"}
    outcome = 2
    with httpx.Client(timeout=30.0, headers=headers) as client:
        base = discover_services_base(client, cfg)
        if base is None:
            log("probe", "abort", stage="discover")
            return 2
        state.services_base = base

        try:
            pre_list = list_services(client, state, label="pre")
            if pre_list is None:
                log("probe", "abort", stage="list_pre")
                return 2
            existing_ids = sorted(s["id"] for s in pre_list if isinstance(s.get("id"), str))
            log("list", "snapshot", existing_count=len(existing_ids), existing_ids=existing_ids)

            created = create_service(client, state)
            if created is None:
                log("probe", "abort", stage="create")
                return 2

            if get_service(client, state, label="post_create") is None:
                log("probe", "abort", stage="get_post_create")
                return 2
            if not patch_service(client, state):
                log("probe", "abort", stage="patch")
                return 2
            if get_service(client, state, label="post_patch") is None:
                log("probe", "abort", stage="get_post_patch")
                return 2

            mid_list = list_services(client, state, label="mid")
            if mid_list is None:
                log("probe", "abort", stage="list_mid")
                return 2
            mid_has_ours = any(s.get("id") == state.service_id for s in mid_list)
            log("list", "presence_check", label="mid", present=mid_has_ours, total=len(mid_list))

            log("probe", "all_steps_passed_pre_teardown")
            outcome = 0
        finally:
            teardown(client, state)
            post_list = list_services(client, state, label="post")
            if post_list is not None and state.service_id is not None:
                still_present = any(s.get("id") == state.service_id for s in post_list)
                log(
                    "list",
                    "presence_check",
                    label="post",
                    still_present=still_present,
                    total=len(post_list),
                )

    if state.orphans:
        log("probe", "orphans_present", orphans=state.orphans)
        return 2
    log("probe", "clean_exit", status_code=outcome)
    return outcome


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="ADR-023 Probe 3: Sinch fax service CRUD")
    parser.add_argument("--fax-api", default=FAX_API_BASE)
    parser.add_argument("--auth-url", default=AUTH_TOKEN_URL)
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
