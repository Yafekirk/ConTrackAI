<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

/** Bump when the wording of the Terms & Conditions / Privacy Policy changes. */
const CONTRACK_TERMS_VERSION = "2026-01";

$user = require_session();
$userId = (int)$user["id"];
$idEnc = urlencode((string)$userId);
$method = request_method();

/**
 * The user's terms columns, [] when the row is missing, or null when the columns do not exist yet
 * (schema_phase1.sql not re-run). A missing column must never lock anyone out of the app.
 */
function terms_read_row(string $idEnc): ?array
{
    $result = supabase_request("GET", "users?id=eq.{$idEnc}&select=terms_accepted_at,terms_version&limit=1");
    if (!$result["ok"]) {
        $raw = (string)($result["raw"] ?? "");
        $columnsMissing = (stripos($raw, "does not exist") !== false || stripos($raw, "42703") !== false)
            && (stripos($raw, "terms_accepted_at") !== false || stripos($raw, "terms_version") !== false);
        if ($columnsMissing) {
            return null;
        }
        error_log("terms.php supabase failure: " . (string)($result["raw"] ?? $result["error"] ?? ""));
        json_error("Could not read your agreement status. Please try again.", 500);
    }
    $rows = is_array($result["data"] ?? null) ? $result["data"] : [];
    return is_array($rows[0] ?? null) ? $rows[0] : [];
}

if ($method === "GET") {
    $row = terms_read_row($idEnc);
    if ($row === null) {
        json_response([
            "accepted" => true,
            "accepted_at" => null,
            "version" => null,
            "current_version" => CONTRACK_TERMS_VERSION,
            "schema_outdated" => true,
        ]);
    }
    $acceptedAt = $row["terms_accepted_at"] ?? null;
    json_response([
        "accepted" => is_string($acceptedAt) && $acceptedAt !== "",
        "accepted_at" => $acceptedAt,
        "version" => $row["terms_version"] ?? null,
        "current_version" => CONTRACK_TERMS_VERSION,
    ]);
}

if ($method === "POST") {
    $accepted = json_input()["accepted"] ?? null;
    if (!in_array($accepted, [true, "true", 1, "1"], true)) {
        json_error("You must agree to the Terms & Conditions and Privacy Policy to continue.");
    }

    $row = terms_read_row($idEnc)
        ?? json_error("Database schema is outdated. Run sql/schema_phase1.sql in Supabase (users.terms_accepted_at).", 500);

    // Agreement is recorded once — keep the original timestamp on repeat calls.
    $existing = $row["terms_accepted_at"] ?? null;
    if (is_string($existing) && $existing !== "") {
        json_response(["ok" => true, "accepted" => true, "accepted_at" => $existing, "version" => $row["terms_version"] ?? null]);
    }

    $now = gmdate("c");
    $patched = supabase_request("PATCH", "users?id=eq.{$idEnc}", ["terms_accepted_at" => $now, "terms_version" => CONTRACK_TERMS_VERSION]);
    if (!$patched["ok"]) {
        error_log("terms.php accept failed: " . (string)($patched["raw"] ?? $patched["error"] ?? ""));
        json_error("Could not save your agreement. Please try again.", 500);
    }
    audit_log_event("terms_accepted", ["email" => $user["email"] ?? "", "version" => CONTRACK_TERMS_VERSION], $userId);
    json_response(["ok" => true, "accepted" => true, "accepted_at" => $now, "version" => CONTRACK_TERMS_VERSION]);
}

json_error("Method not allowed", 405);
