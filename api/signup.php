<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";
require_once __DIR__ . "/lib_vendor_approval.php";

/** Keep in sync with api/terms.php CONTRACK_TERMS_VERSION. */
const CONTRACK_TERMS_VERSION = "2026-01";

require_method("POST");
$input = json_body();

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

$user = $created["data"][0];
unset($user["password"], $user["approval_token_hash"]);
$userId = (int)($user["id"] ?? 0);

audit_log_event("account_created", ["email" => $email, "role" => "vendor", "approval_status" => "pending"], $userId);
audit_log_event("terms_accepted", ["email" => $email, "version" => CONTRACK_TERMS_VERSION, "source" => "signup"], $userId);

// The vendor is not signed in: the account stays locked until the CEO approves it.
if (contrack_send_vendor_approval_request($user, $approvalToken) === 0) {
    error_log("signup: no CEO approval email was delivered for {$email}");
}
notify_role_users(
    "ceo",
    "New vendor awaiting approval",
    "{$companyName} ({$email}) signed up and needs your approval before they can sign in."
);

json_response([
    "pending" => true,
    "message" => "Your account was created and is waiting for CEO approval. You will receive an email once it is approved.",
    "user" => [
        "company_name" => (string)($user["company_name"] ?? $companyName),
        "email" => (string)($user["email"] ?? $email),
    ],
]);
