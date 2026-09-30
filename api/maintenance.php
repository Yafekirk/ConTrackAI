<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

require_roles(["admin"]);

function rows_or_empty(?array $response): array
{
    if (!$response["ok"] || !is_array($response["data"])) {
        return [];
    }
    return $response["data"];
}

$usersResult = supabase_request(
    "GET",
    "users?select=id,role,created_at,last_login_at,last_seen_at,is_disabled"
);
$users = rows_or_empty($usersResult);

$byRole = [];
$disabled = 0;
$recentSignup = 0;
$cutoffSignup = (new DateTimeImmutable("-7 days"))->format("c");
$activityCutoff = (new DateTimeImmutable("-15 minutes"))->format("c");

foreach ($users as $u) {
    $r = strtolower((string)($u["role"] ?? "vendor"));
    if ($r === "system_admin") {
        $r = "admin";
    }
    $byRole[$r] = ($byRole[$r] ?? 0) + 1;
    if (!empty($u["is_disabled"])) {
        $disabled++;
    }
    $created = (string)($u["created_at"] ?? "");
    if ($created !== "" && $created >= $cutoffSignup) {
        $recentSignup++;
    }
}

$activeRecent = 0;
foreach ($users as $u) {
    $seen = (string)($u["last_seen_at"] ?? "");
    if ($seen !== "" && $seen >= $activityCutoff) {
        $activeRecent++;
    }
}

$pendingResult = supabase_request(
    "GET",
    "vendor_contracts?status=eq.pending&select=id"
);
$pendingRows = rows_or_empty($pendingResult);
$pendingCount = count($pendingRows);

$since = gmdate("c", time() - 86400);
$failedResult = supabase_request(
    "GET",
    "audit_logs?event_type=eq.login_failed&created_at=gte." . rawurlencode($since) . "&select=id"
);
$failedRecent = $failedResult["ok"] ? count(rows_or_empty($failedResult)) : 0;

$auditTail = supabase_request(
    "GET",
    "audit_logs?select=id,event_type,details,actor_user_id,created_at&order=created_at.desc&limit=15"
);

$nlpRecent = supabase_request(
    "GET",
    "nlp_usage?created_at=gte." . rawurlencode($since) . "&select=id,engine"
);
$nlpRows = rows_or_empty($nlpRecent);
$nlpPython = 0;
foreach ($nlpRows as $nlpRow) {
    if (($nlpRow["engine"] ?? "") === "python-ml") {
        $nlpPython++;
    }
}

echo json_encode([
    "total_users" => count($users),
    "users_by_role" => $byRole,
    "disabled_users" => $disabled,
    "recent_signups_7d" => $recentSignup,
    "active_users_recent_15m" => $activeRecent,
    "pending_contracts" => $pendingCount,
    "failed_logins_24h" => $failedRecent,
    "nlp_runs_24h" => count($nlpRows),
    "nlp_python_24h" => $nlpPython,
    "telemetry_note" => "active_users_recent_15m uses last_seen_at (heartbeat + login), not simulated concurrency.",
    "recent_audit" => $auditTail["ok"] ? ($auditTail["data"] ?? []) : [],
]);
