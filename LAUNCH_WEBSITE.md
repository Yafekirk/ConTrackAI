# Launch ConTrack Website (Step by Step)

## 1) Install prerequisites

- Install [PHP 8.2+](https://www.php.net/downloads.php)
- Verify in terminal:
  - `php -v`
- If you get "php is not recognized", add PHP to your Windows `PATH` and reopen PowerShell.

## 2) Set environment variables (PowerShell, current session)

Replace values with your Supabase project credentials:

```powershell
$env:SUPABASE_URL="https://jfzbvopsicpcspwbozpu.supabase.co"
$env:SUPABASE_SERVICE_ROLE_KEY="YOUR_SERVICE_ROLE_KEY"
$env:SUPABASE_PUBLISHABLE_KEY="YOUR_PUBLISHABLE_KEY"
```

Important:
- Keep `SUPABASE_SERVICE_ROLE_KEY` server-side only.
- Do not put it in HTML or frontend JS.
- TLS: the repo includes `certs/cacert.pem` for PHP cURL on Windows. No extra env vars are required unless you override with `SUPABASE_CURL_CA_BUNDLE`.

## 3) Start the PHP web server

From project root:

```powershell
php -S localhost:8000
```

## 4) Open the app

- Open `http://localhost:8000/index.html`

## 4.1) Login accounts

Staff accounts (`admin@contrack.ai`, `manager@contrack.ai`, `ceo@contrack.ai`) are seeded in
the `users` table. Vendors register themselves through `Signup.html`. All passwords are stored
as `password_hash` values; a password can only be changed by its owner via `api/account.php`,
or reset by an admin re-seeding the row.

## 5) Test API connectivity quickly

The API requires a login, so an anonymous browser hit returns `401 Sign in required`. That is
the expected response. To exercise the endpoints, sign in through the UI first, then open:

- `http://localhost:8000/api/contracts.php?view=stats`
- `http://localhost:8000/api/contracts.php?view=pending_approvals`

To verify the whole system at once, run the flow checker:

```powershell
python scripts/flow_check.py `
  --login vendor=vendor@example.com:PASSWORD `
  --login manager=manager@contrack.ai:PASSWORD `
  --login ceo=ceo@contrack.ai:PASSWORD `
  --login admin=admin@contrack.ai:PASSWORD
```

With no `--login` flags it still verifies that every endpoint rejects unauthenticated and
role-header-spoofed requests.

## 6) Role context in frontend calls

Roles come from the PHP session created by `api/auth.php` — the server never trusts a role sent
by the browser. `setApiContext()` only affects local UI labelling:

```js
import { setApiContext } from "/assets/js/api-client.js";

setApiContext({
  role: "manager", // UI hint only; the server uses the session role
  userId: 1,
});
```

## 7) Production deployment notes

- Deploy PHP files (`api/`) and frontend files to your web host.
- Set the same env vars on the server host.
- Ensure HTTPS is enabled.
- Rotate any secret key that was previously exposed in chat/messages.

## 8) Security checklist before go-live

- Keep all Supabase secret keys server-side only; never in HTML or frontend JS.
- Serve the site over HTTPS, then set `session.cookie_secure=1` in `php.ini`.
- Leave `CONTRACK_DEV_HEADER_AUTH` unset. Setting it to `true` makes the API trust the
  `X-User-Role` / `X-User-Id` headers when there is no session, which lets any caller claim
  any role. It exists only for local API testing.
- Row Level Security is enabled with no policies on every table, so the publishable key
  cannot read data; only the server's service role key can. Keep it that way.
- Passwords are stored with `password_hash`. Legacy plain-text values are upgraded to a hash
  automatically on the owner's next successful login.

## 9) Session auth endpoints

- Login: `POST /api/auth.php`
- Current session: `GET /api/session.php`
- Logout: `DELETE /api/session.php`

