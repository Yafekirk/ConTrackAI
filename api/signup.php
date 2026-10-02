<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_validation.php";

/** Keep in sync with api/terms.php CONTRACK_TERMS_VERSION. */
const CONTRACK_TERMS_VERSION = "2026-01";

if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "POST") {
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

$acceptedTerms = $input["accepted_terms"] ?? $input["accept_terms"] ?? null;
$termsOk = $acceptedTerms === true || $acceptedTerms === "true" || $acceptedTerms === 1 || $acceptedTerms === "1";
if (!$termsOk) {
    http_response_code(400);
    echo json_encode(["error" => "You must agree to the Terms & Conditions and Privacy Policy to create an account."]);
    exit;
}

$companyName = trim((string)($input["company_name"] ?? $input["name"] ?? ""));
$email = strtolower(trim((string)($input["email"] ?? "")));
$password = (string)($input["password"] ?? "");
$contactNumber = contrack_normalize_contact((string)($input["contact_number"] ?? ""));
$supplierType = trim((string)($input["supplier_type"] ?? ""));

$confirmPassword = (string)($input["confirm_password"] ?? "");
if (!hash_equals($password, $confirmPassword)) {
    http_response_code(400);
    echo json_encode(["error" => "Passwords do not match."]);
    exit;
}

$errors = [
    contrack_company_error($companyName, true),
    contrack_email_error($email),
    contrack_contact_error($contactNumber, true),
    contrack_supplier_error($supplierType, true),
    contrack_password_error($password),
];
$errors = array_values(array_filter($errors));
if ($errors !== []) {
    http_response_code(400);
    echo json_encode(["error" => $errors[0]]);
    exit;
}

$now = gmdate("c");
$payload = [[
    "name" => $companyName,
    "company_name" => $companyName,
    "email" => $email,
    "password" => password_hash($password, PASSWORD_DEFAULT),
    "role" => "vendor",
    "contact_number" => $contactNumber,
    "supplier_type" => $supplierType,
    "terms_accepted_at" => $now,
    "terms_version" => CONTRACK_TERMS_VERSION,
]];

$created = supabase_request("POST", "users", $payload);
if (!$created["ok"] || !is_array($created["data"]) || count($created["data"]) === 0) {
    $lowLevelError = (string)($created["error"] ?? "");
    $raw = (string)($created["raw"] ?? "");
    $duplicate = stripos($raw, "duplicate key") !== false || stripos($raw, "23505") !== false;
    $invalidKey = stripos($raw, "Invalid API key") !== false || stripos($raw, "JWT") !== false;
    $missingCurl = stripos($lowLevelError, "cURL extension") !== false;
    $checkFail = stripos($raw, "check constraint") !== false || stripos($raw, "23514") !== false;
    $termsMissing = (stripos($raw, "does not exist") !== false || stripos($raw, "42703") !== false)
        && (stripos($raw, "terms_accepted_at") !== false || stripos($raw, "terms_version") !== false);

    // Older schemas without terms columns: create the account, then terms are recorded after session starts.
    if ($termsMissing) {
        $payload = [[
            "name" => $companyName,
            "company_name" => $companyName,
            "email" => $email,
            "password" => password_hash($password, PASSWORD_DEFAULT),
            "role" => "vendor",
            "contact_number" => $contactNumber,
            "supplier_type" => $supplierType,
        ]];
        $created = supabase_request("POST", "users", $payload);
    }

    if (!$created["ok"] || !is_array($created["data"]) || count($created["data"]) === 0) {
        $lowLevelError = (string)($created["error"] ?? "");
        $raw = (string)($created["raw"] ?? "");
        $duplicate = stripos($raw, "duplicate key") !== false || stripos($raw, "23505") !== false;
        $invalidKey = stripos($raw, "Invalid API key") !== false || stripos($raw, "JWT") !== false;
        $missingCurl = stripos($lowLevelError, "cURL extension") !== false;
        $checkFail = stripos($raw, "check constraint") !== false || stripos($raw, "23514") !== false;

        if ($duplicate) {
            http_response_code(409);
            echo json_encode(["error" => "Email is already registered"]);
            exit;
        }

        if ($checkFail) {
            http_response_code(400);
            echo json_encode(["error" => "Account details do not meet signup requirements."]);
            exit;
        }

        if ($invalidKey) {
            http_response_code(500);
            error_log("signup: Supabase key rejected: " . substr($raw, 0, 300));
            echo json_encode(["error" => env_flag("CONTRACK_DEBUG", false)
                ? "Supabase key is invalid or missing. Re-check SUPABASE_SERVICE_ROLE_KEY before starting the PHP server."
                : "Account service is temporarily unavailable."]);
            exit;
        }

        if ($missingCurl) {
            http_response_code(500);
            error_log("signup: " . $lowLevelError);
            echo json_encode(["error" => env_flag("CONTRACK_DEBUG", false) ? $lowLevelError : "Account service is temporarily unavailable."]);
            exit;
        }

        http_response_code($created["status"] ?: 500);
        echo json_encode(["error" => contrack_upstream_error($created, "Failed to create account")]);
        exit;
    }
}

$user = $created["data"][0];
unset($user["password"]);
contrack_start_authenticated_session($user);

audit_log_event("account_created", ["email" => $email, "role" => "vendor"], (int)($user["id"] ?? 0));
audit_log_event(
    "terms_accepted",
    ["email" => $email, "version" => CONTRACK_TERMS_VERSION, "source" => "signup"],
    (int)($user["id"] ?? 0)
);

echo json_encode(["user" => $user]);
