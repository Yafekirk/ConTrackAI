<?php
declare(strict_types=1);

ini_set("display_errors", "0");
ini_set("html_errors", "0");
// Never echo PHP errors to clients, but do log every notice/warning so bugs stay visible in the server log.
ini_set("log_errors", "1");
error_reporting(E_ALL & ~E_DEPRECATED);

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Reject session ids the server did not issue (blocks session fixation via a planted cookie).
    ini_set("session.use_strict_mode", "1");
    // The session is the only credential the portals hold, so give it a full work day
    // instead of PHP's 24-minute default.
    $sessionLifetime = 8 * 60 * 60;
    ini_set("session.gc_maxlifetime", (string)$sessionLifetime);
    session_set_cookie_params([
        "lifetime" => $sessionLifetime,
        "path" => "/",
        "httponly" => true,
        "samesite" => "Lax",
        // Set CONTRACK_HTTPS=true once the site is served over TLS.
        "secure" => env_flag("CONTRACK_HTTPS", false),
    ]);
    if (env_value("SUPABASE_URL") !== null && env_value("SUPABASE_SERVICE_ROLE_KEY") !== null
        && !env_flag("CONTRACK_FILE_SESSIONS", false)) {
        require_once __DIR__ . "/lib_session_store.php";
        session_set_save_handler(new ContrackSessionStore($sessionLifetime), true);
    }
    session_start();
    // Strict mode answers an unknown or stale cookie with a fresh empty session id. When that
    // response lands after a parallel sign-in it overwrites the new login cookie and signs the
    // user out, so an id that never held data is not sent back to the browser.
    $incomingSessionId = (string)($_COOKIE[session_name()] ?? "");
    header_register_callback(static function () use ($incomingSessionId): void {
        $currentId = session_id();
        if ($currentId !== "" && $currentId !== $incomingSessionId && empty($_SESSION)) {
            header_remove("Set-Cookie");
        }
    });
}

if (!defined("CONTRACK_SKIP_JSON_HEADER") || CONTRACK_SKIP_JSON_HEADER !== true) {
    header("Content-Type: application/json; charset=utf-8");
}

function env_value(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    if ($value === false || $value === "") {
        return $default;
    }
    return $value;
}

function require_env(string $key): string
{
    return env_value($key) ?? json_error("Missing required environment variable: {$key}", 500);
}

function env_flag(string $key, bool $default = false): bool
{
    $value = env_value($key);
    return $value === null ? $default : in_array(strtolower(trim($value)), ["1", "true", "yes", "on"], true);
}

// ---------------------------------------------------------------------------
// JSON responses: every endpoint answers through these.
// ---------------------------------------------------------------------------

function json_response($data, int $status = 200): never
{
    http_response_code($status);
    $body = (string)json_encode($data);
    // php -S never compresses; contract lists are large enough that gzip noticeably shortens page loads.
    if (strlen($body) > 2048 && !headers_sent() && function_exists("gzencode")
        && stripos((string)($_SERVER["HTTP_ACCEPT_ENCODING"] ?? ""), "gzip") !== false) {
        $gz = gzencode($body, 5);
        if ($gz !== false) {
            header("Content-Encoding: gzip");
            header("Vary: Accept-Encoding");
            $body = $gz;
        }
    }
    echo $body;
    exit;
}

/** File holding the time of the last write that changes what the portals show (read by api/pulse.php). */
function contrack_pulse_file(): string
{
    return sys_get_temp_dir() . DIRECTORY_SEPARATOR . "contrack_pulse_" . substr(sha1(dirname(__DIR__)), 0, 12);
}

/**
 * True when a successful write changes data another portal displays. Session rows, audit rows, NLP usage,
 * password resets, read/unread flips and login/heartbeat timestamps are left out so they never wake every open tab.
 */
function contrack_write_moves_portals(string $method, string $path, ?array $payload): bool
{
    if (strtoupper($method) === "GET") {
        return false;
    }
    $table = (string)strtok(ltrim($path, "/"), "?");
    if (in_array($table, ["vendor_contracts", "vendor_evaluations", "vendor_evaluation_scores", "vendor_score_criteria"], true)) {
        return true;
    }
    if ($table === "alerts") {
        return strtoupper($method) === "POST";
    }
    if ($table === "users") {
        if (strtoupper($method) !== "PATCH") {
            return true;
        }
        $row = is_array($payload) && isset($payload[0]) && is_array($payload[0]) ? $payload[0] : ($payload ?? []);
        return array_intersect(array_keys($row), ["role", "is_disabled", "name", "company_name", "approval_status"]) !== [];
    }
    return false;
}

function contrack_bump_pulse(): void
{
    @file_put_contents(contrack_pulse_file(), sprintf("%.6f", microtime(true)), LOCK_EX);
}

function json_error(string $message, int $status = 400): never
{
    json_response(["error" => $message], $status);
}

/** Answer with a Supabase result: its data on success, a safe error message otherwise. */
function json_result(array $result, string $fallback, $default = []): never
{
    json_response(
        $result["ok"] ? ($result["data"] ?? $default) : ["error" => contrack_upstream_error($result, $fallback)],
        (int)$result["status"]
    );
}

function request_method(): string
{
    return strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
}

function require_method(string $method): void
{
    if (request_method() !== $method) {
        json_error("Method not allowed", 405);
    }
}

/** Decoded JSON object from the request body, or null when the body is not one. */
function json_input(): ?array
{
    $input = json_decode((string)file_get_contents("php://input"), true);
    return is_array($input) ? $input : null;
}

/** Like json_input(), but answers 400 "Invalid request body" when the body is not a JSON object. */
function json_body(): array
{
    return json_input() ?? json_error("Invalid request body");
}

/**
 * Resolve a CA bundle for cURL TLS verification (Windows PHP often has none configured).
 */
function resolve_supabase_ca_bundle(): ?string
{
    $explicit = env_value("SUPABASE_CURL_CA_BUNDLE");
    if ($explicit !== null && is_readable($explicit)) {
        return $explicit;
    }

    $projectRoot = dirname(__DIR__);
    $bundled = $projectRoot . DIRECTORY_SEPARATOR . "certs" . DIRECTORY_SEPARATOR . "cacert.pem";
    if (is_readable($bundled)) {
        return $bundled;
    }

    foreach (["curl.cainfo", "openssl.cafile"] as $iniKey) {
        $iniPath = ini_get($iniKey);
        if (is_string($iniPath) && $iniPath !== "" && is_readable($iniPath)) {
            return $iniPath;
        }
    }

    if (defined("PHP_BINARY")) {
        $phpDir = dirname(PHP_BINARY);
        $candidates = [
            $phpDir . DIRECTORY_SEPARATOR . "extras" . DIRECTORY_SEPARATOR . "ssl" . DIRECTORY_SEPARATOR . "cacert.pem",
            $phpDir . DIRECTORY_SEPARATOR . "cacert.pem",
        ];
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
    }

    return null;
}

/**
 * Dev-only escape hatch: trust X-User-Role / X-User-Id (or ?role= / ?user_id=) when no
 * session exists. Never enable in production — it lets any caller claim any role.
 */
function contrack_header_auth_allowed(): bool
{
    return env_flag("CONTRACK_DEV_HEADER_AUTH", false);
}

function get_request_role(): string
{
    $user = get_session_user();
    $role = strtolower(trim((string)($user["role"] ?? "")));
    $allowed = ["system_admin", "admin", "manager", "ceo", "vendor", "client", "vendor_client"];
    return in_array($role, $allowed, true) ? $role : "vendor";
}

function normalize_role(string $role): string
{
    if ($role === "system_admin") {
        return "admin";
    }
    if ($role === "vendor_client" || $role === "client") {
        return "vendor";
    }
    return $role;
}

/**
 * Every data endpoint must run behind a real login; role headers alone are not identity.
 */
function require_session(): array
{
    $user = get_session_user();
    if ($user === null || !contrack_session_revalidate()) {
        json_error("Sign in required", 401);
    }
    $user = get_session_user() ?? $user;
    // PHP holds an exclusive lock on the session file until the request ends, which makes every
    // parallel API call from the same browser wait in line. Release it once the user is known;
    // the few writers reopen it through contrack_session_reopen().
    contrack_release_session();
    return $user;
}

function contrack_release_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

/** Reacquire the session after contrack_release_session() so it can be written again. */
function contrack_session_reopen(): void
{
    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
        session_start();
    }
}

function require_roles(array $roles): string
{
    require_session();
    $role = normalize_role(get_request_role());
    if (!in_array($role, $roles, true)) {
        json_error("Forbidden for role: {$role}", 403);
    }
    return $role;
}

/** Signed-in user id, answering 401 when the session carries none. */
function require_user_id(): int
{
    require_session();
    return get_request_user_id() ?? json_error("Sign in required", 401);
}

function get_request_user_id(): ?int
{
    $user = get_session_user();
    $raw = $user["id"] ?? null;
    if ($raw === null || $raw === "") {
        return null;
    }
    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || (int)$value <= 0) {
        return null;
    }
    return (int)$value;
}

/**
 * Start a fresh authenticated session after login/signup. Regenerating the id prevents session fixation.
 */
function contrack_start_authenticated_session(array $user): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
    set_session_user($user);
}

function contrack_destroy_session(): void
{
    contrack_session_reopen();
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), "", time() - 42000, $params["path"], $params["domain"], (bool)$params["secure"], (bool)$params["httponly"]);
        }
        session_destroy();
    }
}

/**
 * Re-check the signed-in account against the database at most every CONTRACK_REVALIDATE_SECONDS
 * (default 30). A page fires several API calls at once, so checking on every single call doubled
 * the database round trips. Disabled or deleted accounts lose their session within that window,
 * and role changes apply within it.
 * A failed lookup keeps the session so a database hiccup does not sign everyone out.
 */
function contrack_session_revalidate(): bool
{
    static $result = null;
    if ($result !== null) {
        return $result;
    }
    $sessionUser = $_SESSION["contrack_user"] ?? null;
    if (!is_array($sessionUser) || empty($sessionUser["id"])) {
        // No real session (dev header auth or nobody signed in): nothing to revalidate.
        return $result = true;
    }
    $ttl = max(0, (int)(env_value("CONTRACK_REVALIDATE_SECONDS", "30") ?? 30));
    $checkedAt = (int)($_SESSION["contrack_checked_at"] ?? 0);
    if ($ttl > 0 && $checkedAt > 0 && (time() - $checkedAt) < $ttl) {
        return $result = true;
    }
    $id = (int)$sessionUser["id"];
    $lookup = supabase_request(
        "GET",
        "users?id=eq.{$id}&select=id,name,company_name,email,role,is_disabled&limit=1"
    );
    if (!$lookup["ok"] || !is_array($lookup["data"])) {
        error_log("session revalidation skipped: " . (string)($lookup["raw"] ?? $lookup["error"] ?? ""));
        return $result = true;
    }
    $row = $lookup["data"][0] ?? null;
    if (!is_array($row) || !empty($row["is_disabled"])) {
        contrack_destroy_session();
        return $result = false;
    }
    set_session_user($row);
    return $result = true;
}

/**
 * Message safe to show a client. The raw upstream body is always logged; it is only returned
 * when CONTRACK_DEBUG=true (local development).
 */
function contrack_upstream_error(array $result, string $fallback): string
{
    $detail = (string)($result["raw"] ?? $result["error"] ?? "");
    if ($detail !== "") {
        error_log("upstream error ({$fallback}): " . substr($detail, 0, 1000));
    }
    if ($detail !== "" && env_flag("CONTRACK_DEBUG", false)) {
        return $detail;
    }
    return $fallback;
}

/**
 * Compare a submitted password with the stored value.
 * Hashes use password_verify. Legacy plain text rows are compared in constant time and
 * reported through $needsUpgrade so the caller can re-save them as hashes.
 */
function contrack_password_matches(string $stored, string $provided, bool &$needsUpgrade = false): bool
{
    $needsUpgrade = false;
    if ($stored === "" || $provided === "") {
        return false;
    }
    $info = password_get_info($stored);
    if (($info["algo"] ?? null) !== null && $info["algo"] !== 0) {
        $ok = password_verify($provided, $stored);
        $needsUpgrade = $ok && password_needs_rehash($stored, PASSWORD_DEFAULT);
        return $ok;
    }
    if (hash_equals($stored, $provided)) {
        $needsUpgrade = true;
        return true;
    }
    return false;
}

/**
 * True when $candidate equals the user's current password or any earlier one kept in
 * password_history. A missing history table only skips the history part of the check.
 */
function contrack_password_was_used(int $userId, string $currentStored, string $candidate): bool
{
    if (contrack_password_matches($currentStored, $candidate)) {
        return true;
    }
    $history = supabase_request(
        "GET",
        "password_history?user_id=eq.{$userId}&select=password_hash"
    );
    if (!$history["ok"] || !is_array($history["data"])) {
        error_log("password_history lookup failed: " . (string)($history["raw"] ?? $history["error"] ?? ""));
        return false;
    }
    foreach ($history["data"] as $row) {
        if (contrack_password_matches((string)($row["password_hash"] ?? ""), $candidate)) {
            return true;
        }
    }
    return false;
}

/** Save a new password and move the replaced one into password_history. */
function contrack_set_password(int $userId, string $currentStored, string $newPassword): array
{
    $patch = supabase_request(
        "PATCH",
        "users?id=eq.{$userId}",
        ["password" => password_hash($newPassword, PASSWORD_DEFAULT)]
    );
    if ($patch["ok"] && $currentStored !== "") {
        $info = password_get_info($currentStored);
        $oldHash = (($info["algo"] ?? null) !== null && $info["algo"] !== 0)
            ? $currentStored
            : password_hash($currentStored, PASSWORD_DEFAULT);
        $saved = supabase_request("POST", "password_history", [[
            "user_id" => $userId,
            "password_hash" => $oldHash,
        ]]);
        if (!$saved["ok"]) {
            error_log("password_history insert failed: " . (string)($saved["raw"] ?? $saved["error"] ?? ""));
        }
    }
    return $patch;
}

function set_session_user(array $user): void
{
    contrack_session_reopen();
    $_SESSION["contrack_checked_at"] = time();
    $_SESSION["contrack_user"] = [
        "id" => (int)($user["id"] ?? 0),
        "name" => (string)($user["name"] ?? ""),
        "company_name" => (string)($user["company_name"] ?? ""),
        "email" => (string)($user["email"] ?? ""),
        "role" => normalize_role((string)($user["role"] ?? "vendor")),
    ];
}

/** Vendor dashboards show the company. Staff keep their personal name. */
function contrack_public_name(array $user): string
{
    $role = normalize_role((string)($user["role"] ?? ""));
    $company = trim((string)($user["company_name"] ?? ""));
    if (($role === "vendor" || $role === "") && $company !== "") {
        return $company;
    }
    $name = trim((string)($user["name"] ?? ""));
    if ($name !== "") {
        return $name;
    }
    return $company !== "" ? $company : "Vendor";
}

function get_session_user(): ?array
{
    $user = $_SESSION["contrack_user"] ?? null;
    if (is_array($user) && !empty($user["id"])) {
        return $user;
    }
    if (!contrack_header_auth_allowed()) {
        return null;
    }
    $headerRole = $_SERVER["HTTP_X_USER_ROLE"] ?? $_GET["role"] ?? null;
    $headerId = $_SERVER["HTTP_X_USER_ID"] ?? $_GET["user_id"] ?? null;
    if ($headerRole === null && $headerId === null) {
        return null;
    }
    $id = filter_var($headerId, FILTER_VALIDATE_INT);
    return [
        "id" => $id === false ? 0 : (int)$id,
        "name" => "",
        "email" => "",
        "role" => normalize_role(strtolower(trim((string)($headerRole ?? "vendor")))),
    ];
}

/**
 * Central error reporting.
 *  - Render: every message goes through error_log(), which the PHP server writes to stderr, so it
 *    shows up in the Render service logs. Search for "[contrack]".
 *  - Supabase: serious problems (uncaught exceptions, fatal errors, database/network failures) are also
 *    saved as audit_logs rows with event_type = 'system_error'. Warnings and expected 4xx stay in the log only.
 * At most 5 rows are written per request, and a failure while saving never raises another error.
 */
function contrack_record_error(string $kind, string $message, array $context = []): void
{
    static $busy = false;
    static $stored = 0;

    $line = "[contrack][{$kind}] {$message}";
    if ($context !== []) {
        $line .= " " . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    error_log(substr($line, 0, 4000));

    if ($busy || $stored >= 5 || env_value("SUPABASE_URL") === null || env_value("SUPABASE_SERVICE_ROLE_KEY") === null) {
        return;
    }
    $busy = true;
    $stored++;
    try {
        $sessionUser = $_SESSION["contrack_user"] ?? [];
        supabase_request("POST", "audit_logs", [[
            "event_type" => "system_error",
            "details" => [
                "kind" => $kind,
                "message" => substr($message, 0, 1500),
                "context" => $context,
                "method" => (string)($_SERVER["REQUEST_METHOD"] ?? "CLI"),
                "path" => (string)parse_url((string)($_SERVER["REQUEST_URI"] ?? ""), PHP_URL_PATH),
                "user_id" => is_array($sessionUser) ? ($sessionUser["id"] ?? null) : null,
                "role" => is_array($sessionUser) ? ($sessionUser["role"] ?? null) : null,
            ],
            "actor_user_id" => null,
            "ip_address" => client_ip(),
            "created_at" => gmdate("c"),
        ]]);
    } catch (Throwable $ignored) {
        // Never let error reporting cause another error.
    } finally {
        $busy = false;
    }
}

function contrack_note_upstream_failure(string $method, string $path, int $status, string $curlError, string $raw): void
{
    // Query strings can hold emails and ids, so only the table/endpoint name is reported.
    $target = strtoupper($method) . " " . (string)strtok($path, "?");
    $detail = $curlError !== "" ? $curlError : substr($raw, 0, 600);
    $serious = $curlError !== "" || $status >= 500;
    if (!$serious || strpos(ltrim($path, "/"), "audit_logs") === 0) {
        error_log("[contrack][supabase] {$target} -> HTTP {$status}: " . substr($detail, 0, 600));
        return;
    }
    contrack_record_error("supabase", "{$target} failed (HTTP {$status}): {$detail}");
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if ((error_reporting() & $severity) === 0) {
        return false; // silenced with @ or excluded: keep PHP's default behaviour
    }
    error_log(sprintf("[contrack][php] %s in %s:%d", $message, basename($file), $line));
    return true;
});

set_exception_handler(static function (Throwable $e): void {
    contrack_record_error("exception", get_class($e) . ": " . $e->getMessage(), [
        "file" => basename($e->getFile()),
        "line" => $e->getLine(),
    ]);
    if (!headers_sent()) {
        http_response_code(500);
    }
    echo json_encode(["error" => "Something went wrong on the server. It has been logged."]);
    exit;
});

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if ($last === null || !in_array($last["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    contrack_record_error("fatal", (string)$last["message"], [
        "file" => basename((string)$last["file"]),
        "line" => (int)$last["line"],
    ]);
});

/** Apply URL, headers, TLS and body options for one Supabase REST call to a (fresh or reset) handle. */
function supabase_configure_handle($ch, string $method, string $path, ?array $payload): void
{
    $url = rtrim(require_env("SUPABASE_URL"), "/") . "/rest/v1/" . ltrim($path, "/");
    $serviceRoleKey = require_env("SUPABASE_SERVICE_ROLE_KEY");
    $headers = [
        "apikey: {$serviceRoleKey}",
        "Authorization: Bearer {$serviceRoleKey}",
        "Content-Type: application/json",
        "Accept: application/json",
        "Prefer: return=representation",
    ];

    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    // Accept gzip: list endpoints return sizeable JSON.
    curl_setopt($ch, CURLOPT_ENCODING, "");
    // Never let a stalled upstream hang the PHP worker indefinitely.
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    // TLS settings for Windows/dev environments:
    // - Prefer strict verification with a CA bundle when available.
    // - Allow explicit insecure mode only when SUPABASE_TLS_INSECURE=true.
    static $caBundlePath = false;
    if ($caBundlePath === false) {
        $caBundlePath = resolve_supabase_ca_bundle();
    }
    $tlsInsecure = env_flag("SUPABASE_TLS_INSECURE", false);
    if ($caBundlePath !== null && !$tlsInsecure) {
        curl_setopt($ch, CURLOPT_CAINFO, $caBundlePath);
    }
    if ($tlsInsecure) {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    } else {
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    }

    if ($payload !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    }

    // Parallel calls wait for the open HTTP/2 connection and ride on it instead of each opening a new one.
    curl_setopt($ch, CURLOPT_PIPEWAIT, true);
}

/**
 * One multi handle per PHP request. Its connection pool outlives each call, so every Supabase call of the
 * request (single or parallel) shares one HTTP/2 connection and only the first pays for the TLS handshake.
 */
function supabase_multi_handle()
{
    static $mh = null;
    if ($mh === null) {
        $mh = curl_multi_init();
        if (defined("CURLMOPT_PIPELINING") && defined("CURLPIPE_MULTIPLEX")) {
            curl_multi_setopt($mh, CURLMOPT_PIPELINING, CURLPIPE_MULTIPLEX);
        }
    }
    return $mh;
}

function supabase_curl_missing(): array
{
    return [
        "ok" => false,
        "status" => 500,
        "error" => "PHP cURL extension is not enabled. Enable extension=curl in php.ini and restart PHP server.",
        "raw" => null,
    ];
}

function supabase_request(string $method, string $path, ?array $payload = null): array
{
    if (!function_exists("curl_init")) {
        return supabase_curl_missing();
    }
    if (function_exists("curl_multi_init")) {
        return supabase_request_many(["single" => [$method, $path, $payload]])["single"];
    }

    // Without curl_multi: one handle per PHP request keeps the HTTPS connection open between calls.
    static $ch = null;
    if ($ch === null) {
        $ch = curl_init();
    } else {
        curl_reset($ch);
    }
    supabase_configure_handle($ch, $method, $path, $payload);

    $raw = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);

    return supabase_finish_response($method, $path, $raw, (int)$status, (string)$error, $payload);
}

/**
 * Run several independent Supabase calls at the same time.
 * $requests maps a key to [method, path, payload?]; the result maps the same keys to supabase_request()-style arrays.
 */
function supabase_request_many(array $requests): array
{
    if ($requests === []) {
        return [];
    }
    if (!function_exists("curl_multi_init")) {
        $out = [];
        foreach ($requests as $key => $req) {
            $out[$key] = supabase_request((string)$req[0], (string)$req[1], $req[2] ?? null);
        }
        return $out;
    }

    $mh = supabase_multi_handle();
    $handles = [];
    foreach ($requests as $key => $req) {
        $ch = curl_init();
        supabase_configure_handle($ch, (string)$req[0], (string)$req[1], $req[2] ?? null);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }

    $codes = [];
    do {
        $status = curl_multi_exec($mh, $running);
        while (($info = curl_multi_info_read($mh)) !== false) {
            $codes[spl_object_id($info["handle"])] = (int)$info["result"];
        }
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    // Detach every handle before finishing: finishing can log an error, which makes its own Supabase call.
    $collected = [];
    foreach ($handles as $key => $ch) {
        $code = $codes[spl_object_id($ch)] ?? 0;
        $error = (string)curl_error($ch);
        if ($error === "" && $code !== 0) {
            $error = curl_strerror($code);
        }
        $httpStatus = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($error === "" && $httpStatus === 0) {
            $error = "request did not complete";
        }
        $collected[$key] = [curl_multi_getcontent($ch), $httpStatus, $error];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }

    $out = [];
    foreach ($collected as $key => [$raw, $httpStatus, $error]) {
        $out[$key] = supabase_finish_response((string)$requests[$key][0], (string)$requests[$key][1], $raw, $httpStatus, $error, $requests[$key][2] ?? null);
    }
    return $out;
}

function supabase_finish_response(string $method, string $path, $raw, int $status, string $error, ?array $payload = null): array
{
    if ($error || $status < 200 || $status >= 300) {
        contrack_note_upstream_failure($method, $path, (int)$status, (string)$error, is_string($raw) ? $raw : "");
    } elseif (contrack_write_moves_portals($method, $path, $payload)) {
        contrack_bump_pulse();
    }

    if ($error) {
        $isTlsCertError = stripos($error, "unable to get local issuer certificate") !== false
            || stripos($error, "SSL certificate problem") !== false
            || stripos($error, "certificate verify failed") !== false;
        if ($isTlsCertError) {
            $bundled = dirname(__DIR__) . DIRECTORY_SEPARATOR . "certs" . DIRECTORY_SEPARATOR . "cacert.pem";
            $hint = "TLS certificate validation failed. Ensure {$bundled} exists (run: Invoke-WebRequest -Uri https://curl.se/ca/cacert.pem -OutFile certs/cacert.pem), set SUPABASE_CURL_CA_BUNDLE, or set SUPABASE_TLS_INSECURE=true for local dev only.";
            return ["ok" => false, "status" => 500, "error" => $hint];
        }
        return ["ok" => false, "status" => 500, "error" => $error];
    }

    $decoded = json_decode((string)$raw, true);
    return [
        "ok" => $status >= 200 && $status < 300,
        "status" => $status,
        "data" => $decoded,
        "raw" => $raw,
    ];
}

function client_ip(): ?string
{
    // Forwarding headers are client-controlled. Only honour them when the app sits behind a
    // proxy you trust (set CONTRACK_TRUST_PROXY=true there); otherwise use the socket address.
    $headers = env_flag("CONTRACK_TRUST_PROXY", false)
        ? ["HTTP_CF_CONNECTING_IP", "HTTP_X_FORWARDED_FOR", "HTTP_X_REAL_IP", "REMOTE_ADDR"]
        : ["REMOTE_ADDR"];
    foreach ($headers as $key) {
        $raw = $_SERVER[$key] ?? null;
        if (!is_string($raw) || $raw === "") {
            continue;
        }
        if ($key === "HTTP_X_FORWARDED_FOR") {
            $parts = array_map("trim", explode(",", $raw));
            $raw = $parts[0] ?? $raw;
        }
        if (filter_var($raw, FILTER_VALIDATE_IP) === false) {
            continue;
        }
        return substr($raw, 0, 128);
    }
    return null;
}

/**
 * Cross-site request guard for state-changing calls. Browsers always send Origin (or Referer)
 * on cross-site POST/PATCH/DELETE, so a request from another site is refused. Calls with neither
 * header (curl, server-to-server) are not browser CSRF and pass through.
 * Extra trusted origins can be listed in CONTRACK_ALLOWED_ORIGINS (comma separated).
 */
function contrack_enforce_same_origin(): void
{
    $method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? ""));
    if ($method === "" || in_array($method, ["GET", "HEAD", "OPTIONS"], true)) {
        return;
    }
    $source = (string)($_SERVER["HTTP_ORIGIN"] ?? "");
    if ($source === "") {
        $source = (string)($_SERVER["HTTP_REFERER"] ?? "");
    }
    if ($source === "") {
        return;
    }
    $parts = parse_url($source);
    $sourceHost = is_array($parts) ? strtolower((string)($parts["host"] ?? "")) : "";
    if ($sourceHost !== "") {
        $sourceAuthority = $sourceHost . (isset($parts["port"]) ? ":" . $parts["port"] : "");
        $hosts = [strtolower((string)($_SERVER["HTTP_HOST"] ?? ""))];
        if (env_flag("CONTRACK_TRUST_PROXY", false) && !empty($_SERVER["HTTP_X_FORWARDED_HOST"])) {
            $hosts[] = strtolower(trim(explode(",", (string)$_SERVER["HTTP_X_FORWARDED_HOST"])[0]));
        }
        if (in_array($sourceAuthority, $hosts, true)) {
            return;
        }
        $allowed = array_filter(array_map("trim", explode(",", strtolower((string)env_value("CONTRACK_ALLOWED_ORIGINS", "")))));
        foreach ($allowed as $entry) {
            $entryHost = parse_url($entry, PHP_URL_HOST);
            $entryPort = parse_url($entry, PHP_URL_PORT);
            $entryAuthority = is_string($entryHost) && $entryHost !== ""
                ? $entryHost . ($entryPort ? ":" . $entryPort : "")
                : $entry;
            if ($entryAuthority === $sourceAuthority) {
                return;
            }
        }
    }
    json_error("Cross-site request blocked", 403);
}

/**
 * Best-effort audit row; ignores failures so auth flows never break if table is missing.
 */
function audit_log_event(string $event_type, array $details = [], ?int $actor_user_id = null): void
{
    [, $path, $payload] = audit_log_request($event_type, $details, $actor_user_id);
    $result = supabase_request("POST", $path, $payload);
    if (!$result["ok"]) {
        error_log("audit_logs insert failed: " . (string)($result["raw"] ?? $result["error"] ?? ""));
    }
}

/** The audit_logs insert as a [method, path, payload] request, for batching with supabase_request_many(). */
function audit_log_request(string $event_type, array $details = [], ?int $actor_user_id = null): array
{
    return ["POST", "audit_logs", [[
        "event_type" => $event_type,
        "details" => $details,
        "actor_user_id" => $actor_user_id,
        "ip_address" => client_ip(),
        "created_at" => gmdate("c"),
    ]]];
}

/**
 * Notification preference column (notify_alerts / notify_announcements), cached per request.
 * Preferences stay on unless the user turned them off; a lookup failure keeps them on so a
 * schema miss does not silence the portal. $remember stores a value already read elsewhere.
 */
function user_notify_pref(int $userId, string $column, ?bool $remember = null): bool
{
    static $cache = [];
    $key = "{$column}:{$userId}";
    if ($remember !== null) {
        return $cache[$key] = $remember;
    }
    if (!array_key_exists($key, $cache)) {
        $result = supabase_request("GET", "users?id=eq.{$userId}&select={$column}&limit=1");
        $row = $result["ok"] ? ($result["data"][0] ?? null) : null;
        $cache[$key] = !is_array($row) || ($row[$column] ?? true) !== false;
    }
    return $cache[$key];
}

/** Contract Updates preference (users.notify_alerts). */
function user_wants_alerts(int $userId, ?bool $remember = null): bool
{
    return user_notify_pref($userId, "notify_alerts", $remember);
}

/** System Announcements preference (users.notify_announcements). */
function user_wants_announcements(int $userId): bool
{
    return user_notify_pref($userId, "notify_announcements");
}

/**
 * Best-effort email copy of an alert. Failures are logged (and recorded in audit_logs) and ignored.
 */
function contrack_email_alert(string $to, string $title, string $message): void
{
    if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
        return;
    }
    require_once __DIR__ . "/lib_mail.php";
    $base = contrack_public_base_url();
    $body = trim($message) . "\r\n\r\n"
        . ($base !== null ? "Sign in to ConTrack AI to view it: {$base}/" : "Sign in to ConTrack AI to view it.")
        . "\r\n\r\nYou receive this email because Contract Updates notifications are turned on in Profile & Settings.";
    $error = contrack_send_mail($to, "ConTrack AI: " . trim($title), $body);
    if ($error !== null) {
        error_log("notification email to {$to} failed: {$error}");
        audit_log_event("notification_email_failed", ["to" => $to, "title" => $title, "error" => $error], null);
    }
}

/**
 * Best-effort in-app alert. Failures are logged and ignored.
 * $kind "contract" follows Contract Updates; "announcement" follows System Announcements.
 * With $email the same alert is also emailed to the user.
 */
function create_user_alert(?int $userId, string $title, string $message, string $kind = "contract", bool $email = false): void
{
    if ($userId === null || $userId <= 0) {
        return;
    }
    $wanted = $kind === "announcement" ? user_wants_announcements($userId) : user_wants_alerts($userId);
    if (!$wanted) {
        return;
    }
    if ($email) {
        $lookup = supabase_request("GET", "users?id=eq.{$userId}&is_disabled=eq.false&select=email&limit=1");
        $address = ($lookup["ok"] && is_array($lookup["data"][0] ?? null)) ? (string)($lookup["data"][0]["email"] ?? "") : "";
        contrack_email_alert($address, $title, $message);
    }
    $payload = [[
        "user_id" => $userId,
        "title" => substr(trim($title), 0, 200),
        "message" => substr(trim($message), 0, 2000),
        "status" => "unread",
        "created_at" => gmdate("c"),
    ]];
    $result = supabase_request("POST", "alerts", $payload);
    if (!$result["ok"]) {
        error_log("alerts insert failed: " . (string)($result["raw"] ?? $result["error"] ?? ""));
    }
}

/**
 * Notify every active user with the given role (manager / ceo / admin / vendor).
 * With $email each recipient also gets the alert by email.
 */
function notify_role_users(string $role, string $title, string $message, bool $email = false): void
{
    $role = normalize_role($role);
    $aliases = ["admin" => ["system_admin"], "vendor" => ["client", "vendor_client"]];
    $roles = array_merge([$role], $aliases[$role] ?? []);
    $ors = implode(",", array_map(static fn(string $r): string => "role.eq.{$r}", $roles));
    $path = "users?select=id,email,notify_alerts&is_disabled=eq.false&or=({$ors})";
    $result = supabase_request("GET", $path);
    if (!$result["ok"] || !is_array($result["data"])) {
        error_log("notify_role_users failed for {$role}: " . (string)($result["raw"] ?? $result["error"] ?? ""));
        return;
    }
    $now = gmdate("c");
    $rows = [];
    foreach ($result["data"] as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = isset($row["id"]) ? (int)$row["id"] : 0;
        $wants = ($row["notify_alerts"] ?? true) !== false;
        if ($id > 0) {
            user_wants_alerts($id, $wants);
        }
        if ($id > 0 && $wants) {
            if ($email) {
                contrack_email_alert((string)($row["email"] ?? ""), $title, $message);
            }
            $rows[] = [
                "user_id" => $id,
                "title" => substr(trim($title), 0, 200),
                "message" => substr(trim($message), 0, 2000),
                "status" => "unread",
                "created_at" => $now,
            ];
        }
    }
    if ($rows === []) {
        return;
    }
    // One insert for every recipient instead of a round trip per user.
    $insert = supabase_request("POST", "alerts", $rows);
    if (!$insert["ok"]) {
        error_log("alerts insert failed: " . (string)($insert["raw"] ?? $insert["error"] ?? ""));
    }
}


contrack_enforce_same_origin();
