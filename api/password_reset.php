<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";
require_once __DIR__ . "/lib_mail.php";

require_method("POST");
$input = json_body();
$action = (string)($input["action"] ?? "");
$email = strtolower(trim((string)($input["email"] ?? "")));
if (($emailError = contrack_email_error($email)) !== null) {
    json_error($emailError);
}

// Match the address saved at signup or when an admin creates the account, ignoring letter case.
$escapedEmail = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $email);
$userResult = supabase_request("GET", "users?email=ilike." . rawurlencode($escapedEmail) . "&select=id,email,role,is_disabled&limit=1");
if (!$userResult["ok"]) {
    json_error("Could not look up that account.", 500);
}
$user = is_array($userResult["data"][0] ?? null) ? $userResult["data"][0] : null;

if ($action === "send") {
    if ($user === null) {
        json_error("No ConTrack account uses that email.", 404);
    }
    if (!empty($user["is_disabled"])) {
        json_error("This account is disabled. Ask an administrator to turn it back on.", 403);
    }

    $userId = (int)$user["id"];
    $since = rawurlencode(gmdate("c", time() - 900));
    $recent = supabase_request("GET", "password_resets?user_id=eq.{$userId}&created_at=gte.{$since}&select=id");
    if ($recent["ok"] && is_array($recent["data"]) && count($recent["data"]) >= 3) {
        json_error("Too many codes were sent. Wait 15 minutes and try again.", 429);
    }

    $code = str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    supabase_request("PATCH", "password_resets?user_id=eq.{$userId}&used_at=is.null", ["used_at" => gmdate("c")]);
    $inserted = supabase_request("POST", "password_resets", [[
        "user_id" => $userId,
        "code_hash" => password_hash($code, PASSWORD_DEFAULT),
        "expires_at" => gmdate("c", time() + 600),
    ]]);
    $rowId = (int)($inserted["data"][0]["id"] ?? 0);
    if (!$inserted["ok"] || $rowId <= 0) {
        json_error("Could not start a password reset.", 500);
    }

    $body = "Your ConTrack AI verification code is {$code}.\r\n\r\n"
        . "It expires in 10 minutes. If you did not ask to reset your password, ignore this email.";
    $mailError = contrack_send_mail($email, "Your ConTrack AI verification code", $body);
    if ($mailError !== null) {
        supabase_request("DELETE", "password_resets?id=eq.{$rowId}");
        json_error($mailError, 502);
    }
    audit_log_event("password_reset_sent", ["email" => $email], $userId);
    json_response(["ok" => true]);
}

if ($user === null || !empty($user["is_disabled"])) {
    json_error("That code is not valid.");
}
$userId = (int)$user["id"];

$open = supabase_request("GET", "password_resets?user_id=eq.{$userId}&used_at=is.null&order=created_at.desc&limit=1");
$row = ($open["ok"] && is_array($open["data"][0] ?? null)) ? $open["data"][0] : null;
if ($row === null) {
    json_error("Request a new verification code.");
}
$resetPath = "password_resets?id=eq." . (int)$row["id"];
$expiresAt = strtotime((string)($row["expires_at"] ?? ""));
if ($expiresAt === false || $expiresAt < time()) {
    supabase_request("PATCH", $resetPath, ["used_at" => gmdate("c")]);
    json_error("That code has expired. Request a new one.");
}

if ($action === "verify") {
    $code = preg_replace("/\D/", "", (string)($input["code"] ?? "")) ?? "";
    $attempts = (int)($row["attempts"] ?? 0);
    if ($attempts >= 5) {
        supabase_request("PATCH", $resetPath, ["used_at" => gmdate("c")]);
        json_error("Too many incorrect codes. Request a new one.", 429);
    }
    if (strlen($code) !== 6 || !password_verify($code, (string)($row["code_hash"] ?? ""))) {
        supabase_request("PATCH", $resetPath, ["attempts" => $attempts + 1]);
        json_error("That code is not valid.");
    }

    $token = bin2hex(random_bytes(32));
    $saved = supabase_request("PATCH", $resetPath, [
        "reset_token_hash" => hash("sha256", $token),
        "expires_at" => gmdate("c", time() + 900),
    ]);
    if (!$saved["ok"]) {
        json_error("Could not confirm that code.", 500);
    }
    json_response(["ok" => true, "reset_token" => $token]);
}

if ($action === "reset") {
    $token = strtolower(trim((string)($input["reset_token"] ?? "")));
    $password = (string)($input["password"] ?? "");
    if (($passwordError = contrack_password_error($password)) !== null) {
        json_error($passwordError);
    }
    $expected = (string)($row["reset_token_hash"] ?? "");
    if ($expected === "" || !hash_equals($expected, hash("sha256", $token))) {
        json_error("Verify the code again before choosing a new password.");
    }

    $current = supabase_request("GET", "users?id=eq.{$userId}&select=password&limit=1");
    if (!$current["ok"]) {
        json_error("Could not update the password.", 500);
    }
    $currentHash = (string)($current["data"][0]["password"] ?? "");
    if (contrack_password_was_used($userId, $currentHash, $password)) {
        json_error("You already used this password before. Choose a new one.");
    }
    if (!contrack_set_password($userId, $currentHash, $password)["ok"]) {
        json_error("Could not update the password.", 500);
    }
    supabase_request("PATCH", $resetPath, ["used_at" => gmdate("c")]);
    audit_log_event("password_reset", ["email" => $email], $userId);
    json_response(["ok" => true]);
}

json_error("Unknown request.");
