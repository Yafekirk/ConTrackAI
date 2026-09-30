<?php
declare(strict_types=1);

const LOGIN_GUARD_FORGOT_AFTER = 3;
const LOGIN_GUARD_LOCK_AFTER = 10;
const LOGIN_GUARD_LOCK_SECONDS = 60;

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
