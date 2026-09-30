<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

/** Bump when the wording of the Terms & Conditions / Privacy Policy changes. */
const CONTRACK_TERMS_VERSION = "2026-01";

$user = require_session();

$userId = (int)$user["id"];
$idEnc = urlencode((string)$userId);
$method = $_SERVER["REQUEST_METHOD"] ?? "GET";

/**
 * True when Postgres rejected the request because the terms columns are absent
 * (schema_phase1.sql not re-run yet).
 */
function terms_columns_missing(array $result): bool
{
    $raw = (string)($result["raw"] ?? "");
    if (stripos($raw, "does not exist") === false && stripos($raw, "42703") === false) {
        return false;
    }
    return stripos($raw, "terms_accepted_at") !== false || stripos($raw, "terms_version") !== false;
}

function terms_backend_failure(array $result): void
{
    error_log("terms.php supabase failure: " . (string)($result["raw"] ?? $result["error"] ?? ""));
    http_response_code(500);
    echo json_encode(["error" => "Could not read your agreement status. Please try again."]);
    exit;
}

function terms_read_row(string $idEnc): ?array
{
    $result = supabase_request(
        "GET",
        "users?id=eq.{$idEnc}&select=terms_accepted_at,terms_version&limit=1"
    );

    if (!$result["ok"]) {
        // A missing column must never lock anyone out of the app.
        if (terms_columns_missing($result)) {
            return null;
        }
        terms_backend_failure($result);
    }

    $rows = is_array($result["data"] ?? null) ? $result["data"] : [];
    return is_array($rows[0] ?? null) ? $rows[0] : [];
}

if ($method === "GET") {
    $row = terms_read_row($idEnc);
    if ($row === null) {
        echo json_encode([
            "accepted" => true,
            "accepted_at" => null,
            "version" => null,
            "current_version" => CONTRACK_TERMS_VERSION,
            "schema_outdated" => true,
        ]);
        exit;
    }

    $acceptedAt = $row["terms_accepted_at"] ?? null;
    echo json_encode([
        "accepted" => is_string($acceptedAt) && $acceptedAt !== "",
        "accepted_at" => $acceptedAt,
        "version" => $row["terms_version"] ?? null,
        "current_version" => CONTRACK_TERMS_VERSION,
    ]);
    exit;
}

if ($method === "POST") {
    $input = json_decode(file_get_contents("php://input"), true);
    $accepted = is_array($input) ? ($input["accepted"] ?? null) : null;
    if ($accepted !== true && $accepted !== "true" && $accepted !== 1 && $accepted !== "1") {
        http_response_code(400);
        echo json_encode(["error" => "You must agree to the Terms & Conditions and Privacy Policy to continue."]);
        exit;
    }

    $row = terms_read_row($idEnc);
    if ($row === null) {
        http_response_code(500);
        echo json_encode([
            "error" => "Database schema is outdated. Run sql/schema_phase1.sql in Supabase (users.terms_accepted_at).",
        ]);
        exit;
    }

    // Agreement is recorded once — keep the original timestamp on repeat calls.
    $existing = $row["terms_accepted_at"] ?? null;
    if (is_string($existing) && $existing !== "") {
        echo json_encode([
            "ok" => true,
            "accepted" => true,
            "accepted_at" => $existing,
            "version" => $row["terms_version"] ?? null,
        ]);
        exit;
    }

    $now = gmdate("c");
    $patched = supabase_request("PATCH", "users?id=eq.{$idEnc}", [
        "terms_accepted_at" => $now,
        "terms_version" => CONTRACK_TERMS_VERSION,
    ]);

    if (!$patched["ok"]) {
        error_log("terms.php accept failed: " . (string)($patched["raw"] ?? $patched["error"] ?? ""));
        http_response_code(500);
        echo json_encode(["error" => "Could not save your agreement. Please try again."]);
        exit;
    }

    audit_log_event(
        "terms_accepted",
        ["email" => $user["email"] ?? "", "version" => CONTRACK_TERMS_VERSION],
        $userId
    );

    echo json_encode([
        "ok" => true,
        "accepted" => true,
        "accepted_at" => $now,
        "version" => CONTRACK_TERMS_VERSION,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(["error" => "Method not allowed"]);
