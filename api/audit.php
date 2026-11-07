<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

require_roles(["admin"]);

$download = isset($_GET["download"]);
$limit = $download ? 500 : max(1, min(500, (int)($_GET["limit"] ?? 100)));
$eventFilter = trim((string)($_GET["event_type"] ?? ""));
$path = "audit_logs?select=id,event_type,details,actor_user_id,ip_address,created_at&order=created_at.desc&limit=" . $limit;
if ($eventFilter !== "") {
    $path .= "&event_type=eq." . urlencode($eventFilter);
}

$result = supabase_request("GET", $path);
if (!$result["ok"] || !$download) {
    json_result($result, "Failed to fetch audit logs");
}

$rows = is_array($result["data"]) ? $result["data"] : [];
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=\"contrack-audit-log.csv\"");
$out = fopen("php://output", "w");
fputcsv($out, ["id", "event_type", "details", "actor_user_id", "ip_address", "created_at"]);
// Neutralise spreadsheet formulas: cells starting with = + - @ (or a tab/CR) get a leading apostrophe.
$csvSafe = static function ($value): string {
    $text = (string)$value;
    return $text !== "" && strpos("=+-@\t\r", $text[0]) !== false ? "'" . $text : $text;
};
foreach ($rows as $row) {
    $details = $row["details"] ?? "";
    if (is_array($details) || is_object($details)) {
        $details = json_encode($details);
    }
    fputcsv($out, [
        $row["id"] ?? "",
        $csvSafe($row["event_type"] ?? ""),
        $csvSafe($details),
        $row["actor_user_id"] ?? "",
        $csvSafe($row["ip_address"] ?? ""),
        $row["created_at"] ?? "",
    ]);
}
fclose($out);
audit_log_event("audit_log_downloaded", ["rows" => count($rows)], get_request_user_id());
