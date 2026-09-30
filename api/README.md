# Supabase + PHP API bridge

This project now includes a PHP API layer in `api/` so frontend pages can connect to Supabase without exposing secret keys in browser code.

## Environment variables (server-side)

Set these in your PHP host environment:

- `SUPABASE_URL`
- `SUPABASE_SERVICE_ROLE_KEY`

Optional (frontend use only):

- `SUPABASE_PUBLISHABLE_KEY`

## Important security note

Do not place `SUPABASE_SERVICE_ROLE_KEY` in frontend JavaScript or HTML.

## Files

- `api/config.php` - shared Supabase REST request function
- `api/contracts.php` - contract and dashboard endpoints
- `api/users.php` - user list / create / update (admin)
- `api/auth.php` - login endpoint for role-based portal routing
- `api/session.php` - session read/logout; `GET ?heartbeat=1` updates `last_seen_at`
- `api/account.php` - change password (session user)
- `api/vendor_scores.php` - manual vendor evaluations (criteria, CRUD)
- `api/audit.php` - read `audit_logs` (admin)
- `api/maintenance.php` - admin maintenance aggregates (users, queue, audit tail)
- `api/signup.php` - public vendor signup endpoint
- `assets/js/api-client.js` - frontend fetch helper

Run `sql/schema_phase1.sql` in Supabase for `audit_logs`, user activity columns, and manager review columns on `vendor_contracts`.

## New contract views

- `GET /api/contracts.php?view=list` - list contracts (role scoped)
- `GET /api/contracts.php?view=stats` - dashboard aggregate stats
- `GET /api/contracts.php?view=performance` - latest stored vendor evaluation (not predicted)
- `GET /api/vendor_scores.php?view=criteria|vendors|list|get`
- `POST /api/vendor_scores.php?view=create|update|delete`
- `POST /api/contracts.php?view=nlp_extract` - NLP-style extraction from plain contract text
- `POST /api/contracts.php?view=create` - create contract (supports NLP auto-fill fallback)

## Authentication and role context

Every endpoint except `auth.php` and `signup.php` requires the PHP session created by
`POST /api/auth.php`. The session holds the authoritative user id and role; requests without
one get `401 Sign in required`.

The `X-User-Role` / `X-User-Id` headers that `assets/js/api-client.js` sends are **ignored**
by the server. They are honoured only when `CONTRACK_DEV_HEADER_AUTH=true` is set in the
server environment, which is for local API testing and must never be enabled in production —
it would let any caller claim any role.

Role scoping applied server-side:

- `vendor` / `client` — only rows where `vendor_contracts.vendor_id` matches the session user
- `manager` — review queue (`manager_pending`, `manager_mine`, `manager_review`)
- `ceo` — decision queue (`pending_approvals`, `decision`)
- `admin` — users, audit log, NLP usage, maintenance

