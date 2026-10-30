<?php
declare(strict_types=1);

/**
 * Change fingerprint for live page refresh (assets/js/live-sync.js).
 * Returns a short hash of the workflow columns the signed-in user can see. The browser polls this
 * and reloads its data when the hash moves, so updates show up without signing out and back in.
 * Alert read/unread status is left out on purpose: marking a notification read must not trigger a reload.
 */

require_once __DIR__ . "/config.php";

require_session();
$role = normalize_role(get_request_role());
$userId = get_request_user_id();
if ($userId === null) {
    http_response_code(401);
    echo json_encode(["error" => "Sign in required"]);
    exit;
}

$contractCols = "id,status,reviewed_at,manager_reviewed_at,recommended_action,is_archived,original_pdf_stored";
$contractScope = $role === "vendor" ? "&vendor_id=eq.{$userId}" : "";

$requests = [
    "contracts" => ["GET", "vendor_contracts?select={$contractCols}{$contractScope}&order=id.asc"],
    "alerts" => ["GET", "alerts?user_id=eq.{$userId}&select=id&order=id.desc&limit=1"],
];
if ($role === "vendor") {
    $requests["scores"] = ["GET", "vendor_evaluations?vendor_id=eq.{$userId}&select=id,updated_at&order=id.asc"];
} else {
    $requests["scores"] = ["GET", "vendor_evaluations?select=id,updated_at&order=id.asc"];
    $requests["users"] = ["GET", "users?select=id,role,is_disabled,name,company_name&order=id.asc"];
}

$results = supabase_request_many($requests);

// Databases that predate the archive columns: fall back to the core workflow columns.
if (!$results["contracts"]["ok"]) {
    $results["contracts"] = supabase_request(
        "GET",
        "vendor_contracts?select=id,status,reviewed_at,manager_reviewed_at{$contractScope}&order=id.asc"
    );
}

$parts = [];
foreach ($results as $key => $res) {
    // A failed section hashes to a constant so a transient error does not look like a change.
    $parts[$key] = $res["ok"] ? sha1((string)($res["raw"] ?? "")) : "unavailable";
}
ksort($parts);

header("Cache-Control: no-store");
echo json_encode([
    "version" => substr(sha1(json_encode($parts)), 0, 16),
    "parts" => array_map(static fn(string $h): string => substr($h, 0, 12), $parts),
]);
