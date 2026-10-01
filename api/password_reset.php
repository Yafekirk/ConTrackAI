<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";
require_once __DIR__ . "/lib_mail.php";

if (strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET")) !== "POST") {
    http_response_code(405);
    echo json_encode(["error" => "Method not allowed"]);
    exit;
}

$input = json_decode(file_get_contents("php://input"), true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid request body"]);
    exit;
}

$action = (string)($input["action"] ?? "");
$email = strtolower(trim((string)($input["email"] ?? "")));
$emailError = contrack_email_error($email);
if ($emailError !== null) {
    http_response_code(400);
    echo json_encode(["error" => $emailError]);
    exit;
}

$userResult = supabase_request(
    "GET",
    "users?email=eq." . rawurlencode($email) . "&select=id,email,is_disabled&limit=1"
);
if (!$userResult["ok"]) {
    http_response_code(500);
    echo json_encode(["error" => "Could not look up that account."]);
    exit;
}
$user = is_array($userResult["data"][0] ?? null) ? $userResult["data"][0] : null;

if ($action === "send") {
    if ($user === null) {
        http_response_code(404);
        echo json_encode(["error" => "No ConTrack account uses that email."]);
        exit;
    }
    if (!empty($user["is_disabled"])) {
        http_response_code(403);
        echo json_encode(["error" => "This account is disabled. Ask an administrator to turn it back on."]);
        exit;
    }

    $userId = (int)$user["id"];
    $since = rawurlencode(gmdate("c", time() - 900));
    $recent = supabase_request(
        "GET",
        "password_resets?user_id=eq.{$userId}&created_at=gte.{$since}&select=id"
    );
    if ($recent["ok"] && is_array($recent["data"]) && count($recent["data"]) >= 3) {
        http_response_code(429);
        echo json_encode(["error" => "Too many codes were sent. Wait 15 minutes and try again."]);
        exit;
    }

    $code = str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    $expires = gmdate("c", time() + 600);
    supabase_request(
        "PATCH",
        "password_resets?user_id=eq.{$userId}&used_at=is.null",
        ["used_at" => gmdate("c")]
    );
    $inserted = supabase_request("POST", "password_resets", [[
        "user_id" => $userId,
        "code_hash" => password_hash($code, PASSWORD_DEFAULT),
        "expires_at" => $expires,
    ]]);
    $rowId = (int)($inserted["data"][0]["id"] ?? 0);
    if (!$inserted["ok"] || $rowId <= 0) {
        http_response_code(500);
        echo json_encode(["error" => "Could not start a password reset."]);
        exit;
    }

    $body = "Your ConTrack AI verification code is {$code}.\r\n\r\n"
        . "It expires in 10 minutes. If you did not ask to reset your password, ignore this email.";
    $mailError = contrack_send_mail($email, "Your ConTrack AI verification code", $body);
    if ($mailError !== null) {
        supabase_request("DELETE", "password_resets?id=eq.{$rowId}");
        http_response_code(502);
        echo json_encode(["error" => $mailError]);
        exit;
    }

    audit_log_event("password_reset_sent", ["email" => $email], $userId);
    echo json_encode(["ok" => true]);
    exit;
}

if ($user === null || !empty($user["is_disabled"])) {
    http_response_code(400);
    echo json_encode(["error" => "That code is not valid."]);
    exit;
}
$userId = (int)$user["id"];

$open = supabase_request(
    "GET",
    "password_resets?user_id=eq.{$userId}&used_at=is.null&order=created_at.desc&limit=1"
);
$row = ($open["ok"] && is_array($open["data"][0] ?? null)) ? $open["data"][0] : null;
if ($row === null) {
    http_response_code(400);
    echo json_encode(["error" => "Request a new verification code."]);
    exit;
}
$expiresAt = strtotime((string)($row["expires_at"] ?? ""));
if ($expiresAt === false || $expiresAt < time()) {
    supabase_request("PATCH", "password_resets?id=eq." . (int)$row["id"], ["used_at" => gmdate("c")]);
    http_response_code(400);
    echo json_encode(["error" => "That code has expired. Request a new one."]);
    exit;
}

if ($action === "verify") {
    $code = preg_replace("/\D/", "", (string)($input["code"] ?? "")) ?? "";
    $attempts = (int)($row["attempts"] ?? 0);
    if ($attempts >= 5) {
        supabase_request("PATCH", "password_resets?id=eq." . (int)$row["id"], ["used_at" => gmdate("c")]);
        http_response_code(429);
        echo json_encode(["error" => "Too many incorrect codes. Request a new one."]);
        exit;
    }
    if (strlen($code) !== 6 || !password_verify($code, (string)($row["code_hash"] ?? ""))) {
        supabase_request(
            "PATCH",
            "password_resets?id=eq." . (int)$row["id"],
            ["attempts" => $attempts + 1]
        );
        http_response_code(400);
        echo json_encode(["error" => "That code is not valid."]);
        exit;
    }

    $token = bin2hex(random_bytes(32));
    $saved = supabase_request("PATCH", "password_resets?id=eq." . (int)$row["id"], [
        "reset_token_hash" => hash("sha256", $token),
        "expires_at" => gmdate("c", time() + 900),
    ]);
    if (!$saved["ok"]) {
        http_response_code(500);
        echo json_encode(["error" => "Could not confirm that code."]);
        exit;
    }
    echo json_encode(["ok" => true, "reset_token" => $token]);
    exit;
}

if ($action === "reset") {
    $token = strtolower(trim((string)($input["reset_token"] ?? "")));
    $password = (string)($input["password"] ?? "");
    $passwordError = contrack_password_error($password);
    if ($passwordError !== null) {
        http_response_code(400);
        echo json_encode(["error" => $passwordError]);
        exit;
    }
    $expected = (string)($row["reset_token_hash"] ?? "");
    if ($expected === "" || !hash_equals($expected, hash("sha256", $token))) {
        http_response_code(400);
        echo json_encode(["error" => "Verify the code again before choosing a new password."]);
        exit;
    }

    $hashed = password_hash($password, PASSWORD_DEFAULT);
    $patch = supabase_request("PATCH", "users?id=eq.{$userId}", ["password" => $hashed]);
    if (!$patch["ok"]) {
        http_response_code(500);
        echo json_encode(["error" => "Could not update the password."]);
        exit;
    }
    supabase_request("PATCH", "password_resets?id=eq." . (int)$row["id"], ["used_at" => gmdate("c")]);
    audit_log_event("password_reset", ["email" => $email], $userId);
    echo json_encode(["ok" => true]);
    exit;
}

http_response_code(400);
echo json_encode(["error" => "Unknown request."]);
