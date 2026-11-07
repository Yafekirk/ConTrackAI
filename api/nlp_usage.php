<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

require_roles(["admin"]);

$columns = ["id", "user_id", "contract_id", "source", "engine", "success", "duration_ms", "source_file_name", "input_kind", "input_chars", "fields_filled", "clause_count", "avg_confidence", "error_message", "ip_address", "created_at"];
$download = isset($_GET["download"]);
$limit = $download ? 500 : max(1, min(500, (int)($_GET["limit"] ?? 100)));
$path = "nlp_usage?select=" . implode(",", $columns) . "&order=created_at.desc&limit=" . $limit;
$filters = ["source" => ["extract", "submit"], "engine" => ["python-ml", "php-regex", "unavailable"]];
foreach ($filters as $key => $allowed) {
    $value = trim((string)($_GET[$key] ?? ""));
    if (in_array($value, $allowed, true)) {
        $path .= "&{$key}=eq." . rawurlencode($value);
    }
}

$result = supabase_request("GET", $path);
if (!$result["ok"] || !$download) {
    json_response(
        $result["ok"] ? (is_array($result["data"]) ? $result["data"] : []) : ["error" => contrack_upstream_error($result, "Failed to fetch NLP usage")],
        $result["ok"] ? 200 : (int)$result["status"]
    );
}

$rows = is_array($result["data"]) ? $result["data"] : [];
header("Content-Type: text/csv; charset=utf-8");
header("Content-Disposition: attachment; filename=\"contrack-nlp-usage.csv\"");
$out = fopen("php://output", "w");
fputcsv($out, $columns);
foreach ($rows as $row) {
    $line = [];
    foreach ($columns as $column) {
        $value = $row[$column] ?? "";
        if ($column === "success") {
            $value = !empty($row["success"]) ? "true" : "false";
        } elseif ($column === "fields_filled" && (is_array($value) || is_object($value))) {
            $value = json_encode($value);
        }
        $line[] = $column === "fields_filled" ? (string)$value : $value;
    }
    fputcsv($out, $line);
}
fclose($out);
audit_log_event("nlp_usage_downloaded", ["rows" => count($rows)], get_request_user_id());
