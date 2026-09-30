<?php
declare(strict_types=1);

require_once __DIR__ . "/config.php";

require_roles(["admin"]);

$limit = (int)($_GET["limit"] ?? 100);
if (isset($_GET["download"])) {
    $limit = 500;
}
if ($limit < 1) {
    $limit = 1;
}
if ($limit > 500) {
    $limit = 500;
}

$source = trim((string)($_GET["source"] ?? ""));
$engine = trim((string)($_GET["engine"] ?? ""));

$path = "nlp_usage?select=id,user_id,contract_id,source,engine,success,duration_ms,source_file_name,input_kind,input_chars,fields_filled,clause_count,avg_confidence,error_message,ip_address,created_at&order=created_at.desc&limit=" . $limit;
if ($source !== "" && in_array($source, ["extract", "submit"], true)) {
    $path .= "&source=eq." . rawurlencode($source);
}
if ($engine !== "" && in_array($engine, ["python-ml", "php-regex", "unavailable"], true)) {
    $path .= "&engine=eq." . rawurlencode($engine);
}

$result = supabase_request("GET", $path);
$rows = ($result["ok"] && is_array($result["data"])) ? $result["data"] : [];

if (isset($_GET["download"])) {
    if (!$result["ok"]) {
        http_response_code($result["status"]);
        echo json_encode(["error" => $result["raw"] ?? "Failed to fetch NLP usage"]);
        exit;
    }
    header("Content-Type: text/csv; charset=utf-8");
    header("Content-Disposition: attachment; filename=\"contrack-nlp-usage.csv\"");
    $out = fopen("php://output", "w");
    fputcsv($out, [
        "id",
        "user_id",
        "contract_id",
        "source",
        "engine",
        "success",
        "duration_ms",
        "source_file_name",
        "input_kind",
        "input_chars",
        "fields_filled",
        "clause_count",
        "avg_confidence",
        "error_message",
        "ip_address",
        "created_at",
    ]);
    foreach ($rows as $row) {
        $fields = $row["fields_filled"] ?? "";
        if (is_array($fields) || is_object($fields)) {
            $fields = json_encode($fields);
        }
        fputcsv($out, [
            $row["id"] ?? "",
            $row["user_id"] ?? "",
            $row["contract_id"] ?? "",
            $row["source"] ?? "",
            $row["engine"] ?? "",
            !empty($row["success"]) ? "true" : "false",
            $row["duration_ms"] ?? "",
            $row["source_file_name"] ?? "",
            $row["input_kind"] ?? "",
            $row["input_chars"] ?? "",
            (string)$fields,
            $row["clause_count"] ?? "",
            $row["avg_confidence"] ?? "",
            $row["error_message"] ?? "",
            $row["ip_address"] ?? "",
            $row["created_at"] ?? "",
        ]);
    }
    fclose($out);
    audit_log_event("nlp_usage_downloaded", ["rows" => count($rows)], get_request_user_id());
    exit;
}

http_response_code($result["ok"] ? 200 : (int)$result["status"]);
echo json_encode($result["ok"] ? $rows : ["error" => $result["raw"] ?? "Failed to fetch NLP usage"]);
