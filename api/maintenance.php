<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

require_roles(["admin"]);

$since = rawurlencode(gmdate("c", time() - 86400));
$batch = supabase_request_many([
    "users" => ["GET", "users?select=id,role,created_at,last_login_at,last_seen_at,is_disabled"],
    "pending" => ["GET", "vendor_contracts?status=eq.pending&select=id"],
    "failed" => ["GET", "audit_logs?event_type=eq.login_failed&created_at=gte.{$since}&select=id"],
    "audit" => ["GET", "audit_logs?select=id,event_type,details,actor_user_id,created_at&order=created_at.desc&limit=15"],
    "nlp" => ["GET", "nlp_usage?created_at=gte.{$since}&select=id,engine"],
]);
$rows = array_map(static fn(array $r): array => $r["ok"] && is_array($r["data"]) ? $r["data"] : [], $batch);

$byRole = [];
$disabled = 0;
$recentSignup = 0;
$activeRecent = 0;
$cutoffSignup = (new DateTimeImmutable("-7 days"))->format("c");
$activityCutoff = (new DateTimeImmutable("-15 minutes"))->format("c");
foreach ($rows["users"] as $u) {
    $r = strtolower((string)($u["role"] ?? "vendor"));
    $r = $r === "system_admin" ? "admin" : $r;
    $byRole[$r] = ($byRole[$r] ?? 0) + 1;
    $disabled += !empty($u["is_disabled"]) ? 1 : 0;
    $created = (string)($u["created_at"] ?? "");
    $recentSignup += ($created !== "" && $created >= $cutoffSignup) ? 1 : 0;
    $seen = (string)($u["last_seen_at"] ?? "");
    $activeRecent += ($seen !== "" && $seen >= $activityCutoff) ? 1 : 0;
}

$nlpPython = count(array_filter($rows["nlp"], static fn($n): bool => ($n["engine"] ?? "") === "python-ml"));

json_response([
    "total_users" => count($rows["users"]),
    "users_by_role" => $byRole,
    "disabled_users" => $disabled,
    "recent_signups_7d" => $recentSignup,
    "active_users_recent_15m" => $activeRecent,
    "pending_contracts" => count($rows["pending"]),
    "failed_logins_24h" => count($rows["failed"]),
    "nlp_runs_24h" => count($rows["nlp"]),
    "nlp_python_24h" => $nlpPython,
    "telemetry_note" => "active_users_recent_15m uses last_seen_at (heartbeat + login), not simulated concurrency.",
    "recent_audit" => $batch["audit"]["ok"] ? ($batch["audit"]["data"] ?? []) : [],
]);
