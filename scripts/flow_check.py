#!/usr/bin/env python3
"""End-to-end flow probe for the ConTrack portals.

Runs against a running PHP server and checks two things:

1. Unauthenticated and role-header-spoofed requests are rejected on every endpoint.
2. With real logins, each role can reach exactly the endpoints it needs, and a
   contract can travel the full vendor -> manager -> CEO workflow.

Usage:
  python scripts/flow_check.py
  python scripts/flow_check.py --base http://localhost:8000
  python scripts/flow_check.py --login vendor=a@b.com:pw --login manager=c@d.com:pw \
                               --login ceo=e@f.com:pw --login admin=g@h.com:pw

Any role you do not pass credentials for is skipped (reported, not failed).
"""
from __future__ import annotations

import argparse
import http.cookiejar
import json
import sys
import urllib.error
import urllib.parse
import urllib.request

ROLE_ORDER = ["vendor", "manager", "ceo", "admin"]

# contracts.php view -> roles that must succeed
CONTRACT_VIEWS = {
    "stats": ["vendor", "manager", "ceo", "admin"],
    "list": ["vendor", "manager", "ceo", "admin"],
    "performance": ["vendor", "manager", "ceo", "admin"],
    "renewals": ["vendor", "manager", "ceo", "admin"],
    "vendor_summary": ["manager", "ceo", "admin"],
    "pending_approvals": ["manager", "ceo", "admin"],
    "manager_pending": ["manager", "admin"],
    "manager_mine": ["manager", "admin"],
}

OTHER_ENDPOINTS = {
    "alerts.php": ["vendor", "manager", "ceo", "admin"],
    "account.php": ["vendor", "manager", "ceo", "admin"],
    "vendor_scores.php": ["vendor", "manager", "ceo", "admin"],
    "users.php": ["admin"],
    "audit.php": ["admin"],
    "nlp_usage.php": ["admin"],
    "maintenance.php": ["admin"],
    "terms.php": ["vendor", "manager", "ceo", "admin"],
}

PROTECTED_PATHS = [
    "/api/users.php",
    "/api/audit.php",
    "/api/nlp_usage.php",
    "/api/maintenance.php",
    "/api/alerts.php",
    "/api/account.php",
    "/api/vendor_scores.php",
    "/api/terms.php",
    "/api/contracts.php?view=list",
    "/api/contracts.php?view=stats",
    "/api/contracts.php?view=manager_pending",
    "/api/contracts.php?view=pending_approvals",
    "/api/contracts.php?view=vendor_summary",
    "/api/contract_pdf.php?id=00000000-0000-0000-0000-000000000000",
]

failures: list[str] = []
notes: list[str] = []


class Client:
    """One browser-like session (keeps the PHP session cookie)."""

    def __init__(self, base: str) -> None:
        self.base = base.rstrip("/")
        self.jar = http.cookiejar.CookieJar()
        self.opener = urllib.request.build_opener(
            urllib.request.HTTPCookieProcessor(self.jar)
        )
        self.user: dict | None = None

    def request(self, path: str, method="GET", body=None, headers=None):
        data = None
        hdrs = {"Accept": "application/json"}
        if body is not None:
            data = json.dumps(body).encode("utf-8")
            hdrs["Content-Type"] = "application/json"
        hdrs.update(headers or {})
        req = urllib.request.Request(
            f"{self.base}{path}", data=data, headers=hdrs, method=method
        )
        try:
            with self.opener.open(req, timeout=120) as resp:
                return resp.status, resp.read().decode("utf-8", "replace")
        except urllib.error.HTTPError as exc:
            return exc.code, exc.read().decode("utf-8", "replace")
        except Exception as exc:  # noqa: BLE001
            return 0, str(exc)

    def login(self, email: str, password: str) -> bool:
        status, body = self.request(
            "/api/auth.php", method="POST", body={"email": email, "password": password}
        )
        if status != 200:
            return False
        try:
            self.user = json.loads(body).get("user")
        except Exception:  # noqa: BLE001
            return False
        return bool(self.user)


def shape(body: str) -> str:
    try:
        data = json.loads(body)
    except Exception:  # noqa: BLE001
        return f"non-json: {body[:80]}"
    if isinstance(data, list):
        return f"list[{len(data)}]"
    if isinstance(data, dict):
        if "error" in data:
            return f"ERROR {str(data['error'])[:110]}"
        return "obj{" + ",".join(list(data.keys())[:5]) + "}"
    return str(data)[:60]


def record(verdict: str, label: str, detail: str) -> None:
    if verdict == "FAIL":
        failures.append(f"{label} — {detail}")
    print(f"{verdict} {label:64} {detail}")


def check_locked_down(base: str) -> None:
    print("\n== 1. Access control (no login)")
    plain = Client(base)
    for path in PROTECTED_PATHS:
        status, body = plain.request(path)
        ok = status in (401, 403)
        record("PASS" if ok else "FAIL", f"no session {path}", f"status={status} {shape(body)}")

    print("\n-- role headers must not grant access without a login")
    for role in ROLE_ORDER:
        spoof = Client(base)
        for path in ["/api/users.php", "/api/contracts.php?view=manager_pending", "/api/alerts.php"]:
            status, body = spoof.request(
                path, headers={"X-User-Role": role, "X-User-Id": "1"}
            )
            ok = status in (401, 403)
            record(
                "PASS" if ok else "FAIL",
                f"spoof X-User-Role={role} {path}",
                f"status={status} {shape(body)}",
            )

    print("\n-- query-string role must not grant access either")
    for path in ["/api/users.php?role=admin&user_id=1", "/api/contracts.php?view=list&role=admin&user_id=1"]:
        status, body = Client(base).request(path)
        ok = status in (401, 403)
        record("PASS" if ok else "FAIL", f"spoof querystring {path}", f"status={status} {shape(body)}")


def check_role_matrix(clients: dict[str, Client]) -> None:
    print("\n== 2. Endpoint access per logged-in role")
    for view, allowed in CONTRACT_VIEWS.items():
        for role in ROLE_ORDER:
            client = clients.get(role)
            if client is None:
                continue
            status, body = client.request(f"/api/contracts.php?view={view}")
            info = shape(body)
            got_ok = 200 <= status < 300 and not info.startswith("ERROR")
            want_ok = role in allowed
            record(
                "PASS" if got_ok == want_ok else "FAIL",
                f"{role:7} contracts.php?view={view}",
                f"status={status} {info}",
            )
    for endpoint, allowed in OTHER_ENDPOINTS.items():
        for role in ROLE_ORDER:
            client = clients.get(role)
            if client is None:
                continue
            status, body = client.request(f"/api/{endpoint}")
            info = shape(body)
            got_ok = 200 <= status < 300 and not info.startswith("ERROR")
            want_ok = role in allowed
            record(
                "PASS" if got_ok == want_ok else "FAIL",
                f"{role:7} {endpoint}",
                f"status={status} {info}",
            )


def check_isolation(clients: dict[str, Client]) -> None:
    """A vendor must only ever see its own contracts."""
    print("\n== 3. Vendor data isolation")
    vendor = clients.get("vendor")
    if vendor is None:
        notes.append("vendor isolation not checked (no vendor login)")
        return
    vid = int(vendor.user["id"])
    status, body = vendor.request("/api/contracts.php?view=list")
    try:
        rows = json.loads(body)
    except Exception:  # noqa: BLE001
        rows = []
    foreign = [r.get("id") for r in rows if isinstance(r, dict) and int(r.get("vendor_id") or 0) != vid]
    record(
        "PASS" if not foreign else "FAIL",
        f"vendor {vid} list returns only own rows",
        f"{len(rows)} row(s), {len(foreign)} foreign",
    )


def check_workflow(clients: dict[str, Client]) -> None:
    print("\n== 4. Full workflow: submit -> manager review -> CEO decision")
    vendor, manager, ceo = clients.get("vendor"), clients.get("manager"), clients.get("ceo")
    if not (vendor and manager and ceo):
        notes.append("workflow not checked (needs vendor + manager + ceo logins)")
        print("SKIP  needs vendor, manager and ceo logins")
        return

    title = "Flow Check Agreement"
    body = {
        "contract_title": title,
        "contract_type": "Lease",
        "contract_value": 1850000,
        "start_date": "2026-10-01",
        "end_date": "2027-09-30",
        "payment_terms": "Net 30",
        "scope": "Automated end-to-end flow verification.",
        "contract_text": (
            "LEASE AGREEMENT\nThe Lessor shall provide warehouse space for PHP 1,850,000.00. "
            "Term begins October 1, 2026 and ends September 30, 2027. Payment terms are Net 30."
        ),
        "pdf_base64": "",
    }
    status, raw = vendor.request("/api/contracts.php?view=create", method="POST", body=body)
    record(
        "PASS" if status in (200, 201) else "FAIL",
        "vendor submits contract",
        f"status={status} {shape(raw)}",
    )
    if status not in (200, 201):
        return
    contract_id = json.loads(raw).get("id")
    print(f"      contract id = {contract_id}")

    # Manager must see it in the unreviewed queue.
    _, raw = manager.request("/api/contracts.php?view=manager_pending")
    queue = [r.get("id") for r in json.loads(raw)] if raw.startswith("[") else []
    record(
        "PASS" if contract_id in queue else "FAIL",
        "manager sees it in review queue",
        f"{len(queue)} pending",
    )

    # CEO must NOT see it yet (manager has not reviewed it).
    _, raw = ceo.request("/api/contracts.php?view=pending_approvals")
    ceo_queue = [r.get("id") for r in json.loads(raw)] if raw.startswith("[") else []
    record(
        "PASS" if contract_id not in ceo_queue else "FAIL",
        "CEO queue excludes unreviewed contract",
        f"{len(ceo_queue)} awaiting decision",
    )

    # CEO approving before review must be refused.
    status, raw = ceo.request(
        "/api/contracts.php?view=decision",
        method="POST",
        body={"id": contract_id, "status": "approved"},
    )
    record(
        "PASS" if status == 409 else "FAIL",
        "CEO cannot approve before manager review",
        f"status={status} {shape(raw)}",
    )

    # Manager reviews.
    status, raw = manager.request(
        "/api/contracts.php?view=manager_review",
        method="POST",
        body={
            "id": contract_id,
            "manager_notes": "Terms verified by automated flow check.",
            "recommended_action": "escalate_to_ceo",
        },
    )
    record(
        "PASS" if 200 <= status < 300 else "FAIL",
        "manager submits review",
        f"status={status} {shape(raw)}",
    )

    # Now it must appear for the CEO and leave the manager queue.
    _, raw = ceo.request("/api/contracts.php?view=pending_approvals")
    ceo_queue = [r.get("id") for r in json.loads(raw)] if raw.startswith("[") else []
    record(
        "PASS" if contract_id in ceo_queue else "FAIL",
        "CEO queue now includes reviewed contract",
        f"{len(ceo_queue)} awaiting decision",
    )
    _, raw = manager.request("/api/contracts.php?view=manager_pending")
    queue = [r.get("id") for r in json.loads(raw)] if raw.startswith("[") else []
    record(
        "PASS" if contract_id not in queue else "FAIL",
        "contract left the manager queue",
        f"{len(queue)} pending",
    )
    _, raw = manager.request("/api/contracts.php?view=manager_mine")
    mine = [r.get("id") for r in json.loads(raw)] if raw.startswith("[") else []
    record(
        "PASS" if contract_id in mine else "FAIL",
        "contract shows under manager's reviews",
        f"{len(mine)} reviewed",
    )

    # Notifications so far.
    for role, client, expect in (
        ("manager", manager, "New contract submitted"),
        ("vendor", vendor, "Manager reviewed your contract"),
        ("ceo", ceo, "Ready for CEO decision"),
    ):
        _, raw = client.request("/api/alerts.php?limit=20")
        titles = [a.get("title") for a in json.loads(raw)] if raw.startswith("[") else []
        record(
            "PASS" if expect in titles else "FAIL",
            f"{role} alert '{expect}'",
            f"{len(titles)} alert(s)",
        )

    # CEO decides.
    status, raw = ceo.request(
        "/api/contracts.php?view=decision",
        method="POST",
        body={"id": contract_id, "status": "approved", "ceo_notes": "Approved by flow check."},
    )
    record(
        "PASS" if 200 <= status < 300 else "FAIL",
        "CEO approves contract",
        f"status={status} {shape(raw)}",
    )

    # Deciding twice must be refused.
    status, raw = ceo.request(
        "/api/contracts.php?view=decision",
        method="POST",
        body={"id": contract_id, "status": "rejected"},
    )
    record(
        "PASS" if status == 409 else "FAIL",
        "CEO cannot re-decide a closed contract",
        f"status={status} {shape(raw)}",
    )

    # Decision notifications.
    for role, client in (("vendor", vendor), ("manager", manager)):
        _, raw = client.request("/api/alerts.php?limit=20")
        titles = [a.get("title") for a in json.loads(raw)] if raw.startswith("[") else []
        hit = any("CEO decision" in str(t) or "CEO decided" in str(t) for t in titles)
        record("PASS" if hit else "FAIL", f"{role} notified of CEO decision", f"{len(titles)} alert(s)")

    # Vendor sees the approved status.
    _, raw = vendor.request("/api/contracts.php?view=list")
    rows = json.loads(raw) if raw.startswith("[") else []
    row = next((r for r in rows if r.get("id") == contract_id), None)
    record(
        "PASS" if row and row.get("status") == "approved" else "FAIL",
        "vendor sees approved status",
        f"status={row.get('status') if row else 'missing'}",
    )

    # Marking an alert read must persist.
    _, raw = vendor.request("/api/alerts.php?limit=5")
    alerts = json.loads(raw) if raw.startswith("[") else []
    if alerts:
        aid = alerts[0].get("id")
        status, _ = vendor.request(
            "/api/alerts.php", method="PATCH", body={"id": aid, "status": "read"}
        )
        _, raw = vendor.request("/api/alerts.php?limit=5")
        again = json.loads(raw) if raw.startswith("[") else []
        now = next((a for a in again if a.get("id") == aid), {})
        record(
            "PASS" if now.get("status") == "read" else "FAIL",
            "alert marked read persists",
            f"status={now.get('status')}",
        )

    # A vendor must not be able to act as a manager or CEO.
    status, raw = vendor.request(
        "/api/contracts.php?view=manager_review",
        method="POST",
        body={"id": contract_id, "manager_notes": "should not work"},
    )
    record("PASS" if status == 403 else "FAIL", "vendor cannot review as manager", f"status={status}")
    status, raw = manager.request(
        "/api/contracts.php?view=decision",
        method="POST",
        body={"id": contract_id, "status": "approved"},
    )
    record("PASS" if status == 403 else "FAIL", "manager cannot make CEO decision", f"status={status}")

    print(f"\n      Test contract left in place: {contract_id}")
    print("      Delete it with:  delete from public.vendor_contracts where id = '%s';" % contract_id)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base", default="http://localhost:8000")
    parser.add_argument(
        "--login",
        action="append",
        default=[],
        metavar="ROLE=EMAIL:PASSWORD",
        help="repeatable, e.g. --login manager=manager@contrack.ai:secret",
    )
    parser.add_argument("--skip-workflow", action="store_true")
    args = parser.parse_args()

    print(f"== ConTrack flow check against {args.base}")

    check_locked_down(args.base)

    clients: dict[str, Client] = {}
    for spec in args.login:
        role, _, creds = spec.partition("=")
        email, _, password = creds.partition(":")
        role = role.strip().lower()
        client = Client(args.base)
        if not client.login(email, password):
            record("FAIL", f"login {role} ({email})", "rejected by /api/auth.php")
            continue
        actual = str(client.user.get("role", "")).lower()
        actual = {"system_admin": "admin", "client": "vendor", "vendor_client": "vendor"}.get(actual, actual)
        record("PASS", f"login {role} ({email})", f"user id={client.user.get('id')} role={actual}")
        if actual != role:
            record("FAIL", f"role of {email}", f"expected {role}, got {actual}")
        clients[role] = client

    if not clients:
        notes.append("No logins supplied — only access control was verified.")
    else:
        check_role_matrix(clients)
        check_isolation(clients)
        if not args.skip_workflow:
            check_workflow(clients)

    print("\n== summary")
    for note in notes:
        print(f"note: {note}")
    if failures:
        print(f"\n{len(failures)} problem(s):")
        for item in failures:
            print(f"  - {item}")
        return 1
    print("all checks passed")
    return 0


if __name__ == "__main__":
    sys.exit(main())
