<?php
declare(strict_types=1);

const LOGIN_GUARD_FORGOT_AFTER = 3;
const LOGIN_GUARD_LOCK_AFTER = 10;
const LOGIN_GUARD_LOCK_SECONDS = 60;

// Server-side limits (cannot be dodged by dropping the session cookie). Failures are read back
// from audit_logs, where every failed login is already recorded with its email and IP address.
const LOGIN_GUARD_WINDOW_SECONDS = 900;
const LOGIN_GUARD_EMAIL_LOCK_AFTER = 10;
const LOGIN_GUARD_IP_LOCK_AFTER = 30;

/**
 * Seconds left on a server-side lock for this email or the caller's IP (0 when not locked).
 * A lookup failure never blocks a login.
 */
function login_guard_server_wait(string $email): int
{
    $since = rawurlencode(gmdate("c", time() - LOGIN_GUARD_WINDOW_SECONDS));
    $base = "audit_logs?event_type=eq.login_failed&created_at=gte.{$since}&select=created_at&order=created_at.desc";
    $checks = [];
    if ($email !== "") {
        $checks[] = [LOGIN_GUARD_EMAIL_LOCK_AFTER, $base . "&details-%3E%3Eemail=eq." . rawurlencode($email)];
    }
    $ip = client_ip();
    if ($ip !== null && $ip !== "") {
        $checks[] = [LOGIN_GUARD_IP_LOCK_AFTER, $base . "&ip_address=eq." . rawurlencode($ip)];
    }
    $wait = 0;
    foreach ($checks as [$limit, $path]) {
        $result = supabase_request("GET", $path . "&limit={$limit}");
        if (!$result["ok"] || !is_array($result["data"]) || count($result["data"]) < $limit) {
            continue;
        }
        $latest = strtotime((string)($result["data"][0]["created_at"] ?? ""));
        if ($latest === false) {
            continue;
        }
        $wait = max($wait, LOGIN_GUARD_LOCK_SECONDS - (time() - $latest));
    }
    return max(0, $wait);
}

function login_guard_reject_if_server_locked(string $email): void
{
    $wait = login_guard_server_wait($email);
    if ($wait <= 0) {
        return;
    }
    http_response_code(429);
    echo json_encode([
        "fails" => (int)($_SESSION["login_fail_count"] ?? 0),
        "locked" => true,
        "retry_after" => $wait,
        "forgot" => false,
    ]);
    exit;
}

function login_guard_payload(): array
{
    $fails = (int)($_SESSION["login_fail_count"] ?? 0);
    $until = (int)($_SESSION["login_lock_until"] ?? 0);
    $now = time();

    if ($until > 0 && $until <= $now) {
        unset($_SESSION["login_fail_count"], $_SESSION["login_lock_until"]);
        $fails = 0;
        $until = 0;
    }

    $locked = $until > $now;
    return [
        "fails" => $fails,
        "locked" => $locked,
        "retry_after" => $locked ? max(0, $until - $now) : 0,
        "forgot" => $fails === LOGIN_GUARD_FORGOT_AFTER && !$locked,
    ];
}

function login_guard_reject_if_locked(): void
{
    $payload = login_guard_payload();
    if (!$payload["locked"]) {
        return;
    }
    http_response_code(429);
    echo json_encode($payload);
    exit;
}

function login_guard_fail(): array
{
    $fails = (int)($_SESSION["login_fail_count"] ?? 0) + 1;
    $_SESSION["login_fail_count"] = $fails;
    if ($fails >= LOGIN_GUARD_LOCK_AFTER) {
        $_SESSION["login_lock_until"] = time() + LOGIN_GUARD_LOCK_SECONDS;
    }
    return login_guard_payload();
}

function login_guard_clear(): void
{
    unset($_SESSION["login_fail_count"], $_SESSION["login_lock_until"]);
}

function login_guard_reject_auth_failure(): void
{
    $payload = login_guard_fail();
    http_response_code($payload["locked"] ? 429 : 401);
    echo json_encode($payload);
    exit;
}
