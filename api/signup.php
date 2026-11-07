<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";
require_once __DIR__ . "/lib_vendor_approval.php";

/** Keep in sync with api/terms.php CONTRACK_TERMS_VERSION. */
const CONTRACK_TERMS_VERSION = "2026-01";

require_method("POST");
$input = json_body();

const SIGNUP_CODE_TTL = 600;
const SIGNUP_CODE_RESEND_AFTER = 60;
const SIGNUP_CODE_MAX_ATTEMPTS = 5;
const SIGNUP_CODES_PER_EMAIL = 3;

/**
 * Step 1 of signup: email a 6-digit code to prove the vendor owns the address. Nothing is saved to the
 * users table until that code comes back, so a fake or mistyped address never becomes an account.
 * The code is kept (hashed) in the visitor's session together with the address it was sent to.
 */
if (($input["action"] ?? "") === "send_code") {
    $email = strtolower(trim((string)($input["email"] ?? "")));
    if (($emailError = contrack_email_error($email) ?? contrack_email_domain_error($email)) !== null) {
        json_error($emailError);
    }

    $pending = $_SESSION["signup_verify"] ?? null;
    if (is_array($pending) && (time() - (int)($pending["sent_at"] ?? 0)) < SIGNUP_CODE_RESEND_AFTER) {
        $wait = SIGNUP_CODE_RESEND_AFTER - (time() - (int)$pending["sent_at"]);
        json_error("Please wait {$wait} seconds before requesting another code.", 429);
    }

    $escapedEmail = str_replace(["\\", "%", "_"], ["\\\\", "\\%", "\\_"], $email);
    $since = rawurlencode(gmdate("c", time() - 900));
    $checks = supabase_request_many([
        "existing" => ["GET", "users?email=ilike." . rawurlencode($escapedEmail) . "&select=id&limit=1"],
        "recent" => ["GET", "audit_logs?event_type=eq.signup_code_sent&created_at=gte.{$since}&details-%3E%3Eemail=eq." . rawurlencode($email) . "&select=id&limit=" . SIGNUP_CODES_PER_EMAIL],
    ]);
    if ($checks["existing"]["ok"] && !empty($checks["existing"]["data"])) {
        json_error("Email is already registered", 409);
    }
    if ($checks["recent"]["ok"] && is_array($checks["recent"]["data"]) && count($checks["recent"]["data"]) >= SIGNUP_CODES_PER_EMAIL) {
        json_error("Too many codes were sent to this email. Wait 15 minutes and try again.", 429);
    }

    $code = str_pad((string)random_int(0, 999999), 6, "0", STR_PAD_LEFT);
    $body = implode("\r\n", [
        "Hello,",
        "",
        "Your ConTrack AI verification code is {$code}.",
        "",
        "Enter this code on the sign-up page to confirm your email address and finish creating your vendor account. It expires in 10 minutes.",
        "",
        "If you did not try to create a ConTrack AI account, you can ignore this email.",
        "",
        "Regards,",
        "ConTrack AI",
    ]);
    $mailError = contrack_send_mail($email, "Your ConTrack AI verification code", $body);
    if ($mailError !== null) {
        error_log("signup code email to {$email} failed: {$mailError}");
        if ($mailError === CONTRACK_MAIL_BAD_RECIPIENT) {
            json_error("The email address you entered is invalid. Please check it and try again.");
        }
        json_error("We could not send the verification email right now. Please try again in a few minutes.", 502);
    }

    $_SESSION["signup_verify"] = [
        "email" => $email,
        "code_hash" => password_hash($code, PASSWORD_DEFAULT),
        "expires" => time() + SIGNUP_CODE_TTL,
        "sent_at" => time(),
        "attempts" => 0,
    ];
    audit_log_event("signup_code_sent", ["email" => $email], null);
    json_response(["ok" => true, "expires_in" => SIGNUP_CODE_TTL, "resend_after" => SIGNUP_CODE_RESEND_AFTER]);
}

$acceptedTerms = $input["accepted_terms"] ?? $input["accept_terms"] ?? null;
if (!in_array($acceptedTerms, [true, "true", 1, "1"], true)) {
    json_error("You must agree to the Terms & Conditions and Privacy Policy to create an account.");
}

$companyName = trim((string)($input["company_name"] ?? $input["name"] ?? ""));
$email = strtolower(trim((string)($input["email"] ?? "")));
$password = (string)($input["password"] ?? "");
$contactNumber = contrack_normalize_contact((string)($input["contact_number"] ?? ""));
$supplierType = trim((string)($input["supplier_type"] ?? ""));

if (!hash_equals($password, (string)($input["confirm_password"] ?? ""))) {
    json_error("Passwords do not match.");
}

$errors = array_values(array_filter([
    contrack_company_error($companyName, true),
    contrack_email_error($email),
    contrack_contact_error($contactNumber, true),
    contrack_supplier_error($supplierType, true),
    contrack_password_error($password),
]));
if ($errors !== []) {
    json_error($errors[0]);
}

// Step 2: the code emailed in step 1 must come back for this exact address before the account exists.
$pending = $_SESSION["signup_verify"] ?? null;
if (!is_array($pending) || ($pending["email"] ?? "") !== $email) {
    json_error("Verify your email address first. Request a verification code.", 422);
}
if ((int)($pending["expires"] ?? 0) < time()) {
    unset($_SESSION["signup_verify"]);
    json_error("Your verification code has expired. Request a new one.", 422);
}
if ((int)($pending["attempts"] ?? 0) >= SIGNUP_CODE_MAX_ATTEMPTS) {
    unset($_SESSION["signup_verify"]);
    json_error("Too many incorrect codes. Request a new one.", 429);
}
$code = preg_replace("/\D/", "", (string)($input["verification_code"] ?? "")) ?? "";
if (strlen($code) !== 6 || !password_verify($code, (string)($pending["code_hash"] ?? ""))) {
    $_SESSION["signup_verify"]["attempts"] = (int)($pending["attempts"] ?? 0) + 1;
    json_error("That verification code is not correct.", 422);
}

$approvalToken = bin2hex(random_bytes(32));
$account = [
    "name" => $companyName,
    "company_name" => $companyName,
    "email" => $email,
    "password" => password_hash($password, PASSWORD_DEFAULT),
    "role" => "vendor",
    "contact_number" => $contactNumber,
    "supplier_type" => $supplierType,
    "approval_status" => "pending",
    "approval_token_hash" => hash("sha256", $approvalToken),
];
$saved = static fn(array $r): bool => $r["ok"] && is_array($r["data"]) && count($r["data"]) > 0;

$created = supabase_request("POST", "users", [$account + ["terms_accepted_at" => gmdate("c"), "terms_version" => CONTRACK_TERMS_VERSION]]);
if (!$saved($created)) {
    // Older schemas without terms columns: create the account without them.
    $raw = (string)($created["raw"] ?? "");
    $termsMissing = (stripos($raw, "does not exist") !== false || stripos($raw, "42703") !== false)
        && (stripos($raw, "terms_accepted_at") !== false || stripos($raw, "terms_version") !== false);
    if ($termsMissing) {
        $created = supabase_request("POST", "users", [$account]);
    }
}

if (!$saved($created)) {
    $lowLevelError = (string)($created["error"] ?? "");
    $raw = (string)($created["raw"] ?? "");
    $has = static fn(string ...$needles): bool => array_filter($needles, static fn(string $n): bool => stripos($raw, $n) !== false) !== [];
    $debug = env_flag("CONTRACK_DEBUG", false);
    if ($has("duplicate key", "23505")) {
        json_error("Email is already registered", 409);
    }
    if ($has("check constraint", "23514")) {
        json_error("Account details do not meet signup requirements.");
    }
    if ($has("Invalid API key", "JWT")) {
        error_log("signup: Supabase key rejected: " . substr($raw, 0, 300));
        json_error($debug
            ? "Supabase key is invalid or missing. Re-check SUPABASE_SERVICE_ROLE_KEY before starting the PHP server."
            : "Account service is temporarily unavailable.", 500);
    }
    if (stripos($lowLevelError, "cURL extension") !== false) {
        error_log("signup: " . $lowLevelError);
        json_error($debug ? $lowLevelError : "Account service is temporarily unavailable.", 500);
    }
    json_error(contrack_upstream_error($created, "Failed to create account"), $created["status"] ?: 500);
}

unset($_SESSION["signup_verify"]);
$user = $created["data"][0];
unset($user["password"], $user["approval_token_hash"]);
$userId = (int)($user["id"] ?? 0);

audit_log_event("account_created", ["email" => $email, "role" => "vendor", "approval_status" => "pending", "email_verified" => true], $userId);
audit_log_event("terms_accepted", ["email" => $email, "version" => CONTRACK_TERMS_VERSION, "source" => "signup"], $userId);

// The vendor is not signed in: the account stays locked until the CEO approves it.
if (contrack_send_vendor_approval_request($user, $approvalToken) === 0) {
    error_log("signup: no CEO approval email was delivered for {$email}");
}
notify_role_users(
    "ceo",
    "New vendor awaiting approval",
    "{$companyName} ({$email}) signed up and needs your approval before they can sign in. Open Vendor Requests to approve or reject."
);

json_response([
    "pending" => true,
    "message" => "Your account was created and is waiting for CEO approval. You will receive an email once it is approved.",
    "user" => [
        "company_name" => (string)($user["company_name"] ?? $companyName),
        "email" => (string)($user["email"] ?? $email),
    ],
]);
