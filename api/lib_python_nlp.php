<?php
declare(strict_types=1);

/**
 * Call the Python ML clause extractor (PDF or text).
 * Returns null when Python is unavailable so PHP regex NLP can run instead.
 */

function python_binary(): ?string
{
    $env = getenv("PYTHON_BINARY") ?: getenv("PYTHON");
    if (is_string($env) && $env !== "" && is_file($env)) {
        return $env;
    }
    $local = getenv("LOCALAPPDATA") ?: "";
    $candidates = [
        dirname(__DIR__) . DIRECTORY_SEPARATOR . "nlp" . DIRECTORY_SEPARATOR . ".venv" . DIRECTORY_SEPARATOR . "Scripts" . DIRECTORY_SEPARATOR . "python.exe",
        dirname(__DIR__) . DIRECTORY_SEPARATOR . "nlp" . DIRECTORY_SEPARATOR . ".venv" . DIRECTORY_SEPARATOR . "bin" . DIRECTORY_SEPARATOR . "python",
        $local . DIRECTORY_SEPARATOR . "Programs" . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "Python312" . DIRECTORY_SEPARATOR . "python.exe",
        $local . DIRECTORY_SEPARATOR . "Programs" . DIRECTORY_SEPARATOR . "Python" . DIRECTORY_SEPARATOR . "Python311" . DIRECTORY_SEPARATOR . "python.exe",
        "C:\\Python312\\python.exe",
        "C:\\Python311\\python.exe",
        "/usr/bin/python3",
        "/usr/bin/python",
    ];
    foreach ($candidates as $path) {
        if ($path !== "" && is_file($path)) {
            return $path;
        }
    }
    return null;
}

function python_nlp_env(): ?array
{
    $env = getenv();
    if (!is_array($env) || $env === []) {
        return null;
    }
    $env["PYTHONUTF8"] = "1";
    $env["PYTHONIOENCODING"] = "utf-8";
    $env["PYTHONLEGACYWINDOWSSTDIO"] = "0";
    return $env;
}

function python_nlp_decode_stdout(string $stdout): ?array
{
    $candidates = [$stdout];
    if (@preg_match("//u", $stdout) !== 1) {
        $converted = function_exists("mb_convert_encoding")
            ? @mb_convert_encoding($stdout, "UTF-8", "Windows-1252")
            : false;
        if (is_string($converted) && $converted !== "") {
            $candidates[] = $converted;
        }
    }
    foreach ($candidates as $blob) {
        $flags = defined("JSON_INVALID_UTF8_SUBSTITUTE") ? JSON_INVALID_UTF8_SUBSTITUTE : 0;
        $decoded = json_decode($blob, true, 512, $flags);
        if (is_array($decoded) && !isset($decoded["error"])) {
            return $decoded;
        }
    }
    return null;
}

function python_nlp_extract(string $text, ?string $pdfPath = null): ?array
{
    $script = dirname(__DIR__) . DIRECTORY_SEPARATOR . "nlp" . DIRECTORY_SEPARATOR . "extract_cli.py";
    if (!is_file($script)) {
        return null;
    }
    $python = python_binary();
    if ($python === null) {
        error_log("python nlp skipped: no python binary");
        return null;
    }

    $workPdf = "";
    if ($pdfPath !== null && is_file($pdfPath)) {
        $workPdf = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "contrack-nlp-" . bin2hex(random_bytes(6)) . ".pdf";
        if (!@copy($pdfPath, $workPdf)) {
            $workPdf = $pdfPath;
        }
    }

    $payload = [
        "text" => $text,
        "pdf_path" => $workPdf,
    ];
    $jsonIn = json_encode($payload, JSON_INVALID_UTF8_SUBSTITUTE);
    if ($jsonIn === false) {
        if ($workPdf !== "" && $workPdf !== $pdfPath) {
            @unlink($workPdf);
        }
        return null;
    }

    $descriptors = [
        0 => ["pipe", "r"],
        1 => ["pipe", "w"],
        2 => ["pipe", "w"],
    ];
    $proc = @proc_open([$python, $script], $descriptors, $pipes, dirname($script), python_nlp_env());
    if (!is_resource($proc)) {
        error_log("python nlp skipped: proc_open failed");
        if ($workPdf !== "" && $workPdf !== $pdfPath) {
            @unlink($workPdf);
        }
        return null;
    }

    fwrite($pipes[0], $jsonIn);
    fclose($pipes[0]);

    stream_set_blocking($pipes[1], true);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($proc);
    if ($workPdf !== "" && $workPdf !== $pdfPath) {
        @unlink($workPdf);
    }
    if ($code !== 0 && ($stdout === false || trim((string)$stdout) === "")) {
        error_log("python nlp failed: " . (string)$stderr);
        return null;
    }

    $decoded = python_nlp_decode_stdout((string)$stdout);
    if (!is_array($decoded) || isset($decoded["error"])) {
        error_log("python nlp bad output: " . substr((string)$stdout, 0, 400) . " stderr=" . (string)$stderr);
        return null;
    }
    $decoded["engine"] = "python-ml";
    return $decoded;
}

/**
 * Non-empty NLP field names from an extract result (no clause text stored).
 *
 * @return list<string>
 */
function nlp_filled_fields(?array $extracted): array
{
    if ($extracted === null) {
        return [];
    }
    $keys = [
        "contract_title",
        "contract_type",
        "contract_value",
        "start_date",
        "end_date",
        "payment_terms",
        "scope",
        "renewal_terms",
        "penalty_clause",
        "financial_obligations",
        "classification",
        "client_name",
        "vendor_name",
        "vendor_address",
        "currency",
        "termination_clause",
        "client_signatory",
        "vendor_signatory",
        "signed_date",
    ];
    $filled = [];
    foreach ($keys as $key) {
        $value = $extracted[$key] ?? null;
        if ($value === null || $value === "" || $value === []) {
            continue;
        }
        $filled[] = $key;
    }
    return $filled;
}

function nlp_input_kind(?string $pdfPath, string $text): ?string
{
    $hasPdf = $pdfPath !== null && $pdfPath !== "" && is_file($pdfPath);
    $hasText = trim($text) !== "";
    if ($hasPdf && $hasText) {
        return "pdf_and_text";
    }
    if ($hasPdf) {
        return "pdf";
    }
    if ($hasText) {
        return "text";
    }
    return null;
}

/**
 * Best-effort NLP usage row. Failures are logged and ignored so extract/submit still succeed.
 *
 * @param array{
 *   user_id?: int|null,
 *   contract_id?: string|null,
 *   source: string,
 *   engine?: string,
 *   success?: bool,
 *   duration_ms?: int|null,
 *   source_file_name?: string|null,
 *   input_kind?: string|null,
 *   input_chars?: int|null,
 *   fields_filled?: list<string>,
 *   clause_count?: int,
 *   avg_confidence?: float|null,
 *   error_message?: string|null
 * } $row
 */
function nlp_usage_log(array $row): void
{
    $engine = (string)($row["engine"] ?? "unavailable");
    if (!in_array($engine, ["python-ml", "php-regex", "unavailable"], true)) {
        $engine = "unavailable";
    }
    $source = (string)($row["source"] ?? "extract");
    if (!in_array($source, ["extract", "submit"], true)) {
        $source = "extract";
    }
    $contractId = $row["contract_id"] ?? null;
    if (!is_string($contractId) || trim($contractId) === "") {
        $contractId = null;
    }
    $userId = $row["user_id"] ?? null;
    if (!is_int($userId) || $userId <= 0) {
        $userId = null;
    }
    $inputKind = $row["input_kind"] ?? null;
    if (!in_array($inputKind, ["pdf", "text", "pdf_and_text"], true)) {
        $inputKind = null;
    }

    $payload = [[
        "user_id" => $userId,
        "contract_id" => $contractId,
        "source" => $source,
        "engine" => $engine,
        "success" => (bool)($row["success"] ?? true),
        "duration_ms" => isset($row["duration_ms"]) ? (int)$row["duration_ms"] : null,
        "source_file_name" => isset($row["source_file_name"]) ? substr((string)$row["source_file_name"], 0, 255) : null,
        "input_kind" => $inputKind,
        "input_chars" => isset($row["input_chars"]) ? (int)$row["input_chars"] : null,
        "fields_filled" => array_values(is_array($row["fields_filled"] ?? null) ? $row["fields_filled"] : []),
        "clause_count" => (int)($row["clause_count"] ?? 0),
        "avg_confidence" => $row["avg_confidence"] ?? null,
        "error_message" => isset($row["error_message"]) ? substr((string)$row["error_message"], 0, 500) : null,
        "ip_address" => client_ip(),
        "created_at" => gmdate("c"),
    ]];
    $result = supabase_request("POST", "nlp_usage", $payload);
    $fields = $payload[0]["fields_filled"];
    $fieldList = is_array($fields) ? implode(",", $fields) : "";
    $line = sprintf(
        "nlp_usage source=%s engine=%s success=%s user=%s file=%s fields=[%s] clauses=%d %sms",
        $source,
        $engine,
        !empty($payload[0]["success"]) ? "true" : "false",
        $userId !== null ? (string)$userId : "-",
        (string)($payload[0]["source_file_name"] ?: "-"),
        $fieldList,
        (int)$payload[0]["clause_count"],
        $payload[0]["duration_ms"] !== null ? (string)$payload[0]["duration_ms"] : "-"
    );
    error_log($line);
    if (!$result["ok"]) {
        error_log("nlp_usage insert failed: " . (string)($result["raw"] ?? $result["error"] ?? ""));
    }
}

/**
 * Time an extract result and write nlp_usage.
 *
 * @param array<string, mixed>|null $extracted
 * @param array<string, mixed> $context
 */
function nlp_usage_from_extract(string $source, ?array $extracted, float $startedAt, array $context = []): void
{
    $engine = is_array($extracted) ? (string)($extracted["engine"] ?? "unavailable") : "unavailable";
    $clauses = is_array($extracted) && isset($extracted["clauses"]) && is_array($extracted["clauses"])
        ? $extracted["clauses"]
        : [];
    $avg = null;
    if ($clauses !== []) {
        $sum = 0.0;
        foreach ($clauses as $clause) {
            $sum += (float)($clause["confidence"] ?? 0);
        }
        $avg = round($sum / count($clauses), 4);
    }
    $textLen = 0;
    if (is_array($extracted) && isset($extracted["contract_text"])) {
        $textLen = strlen((string)$extracted["contract_text"]);
    }
    if ($textLen === 0 && isset($context["input_chars"])) {
        $textLen = (int)$context["input_chars"];
    }
    nlp_usage_log([
        "user_id" => $context["user_id"] ?? null,
        "contract_id" => $context["contract_id"] ?? null,
        "source" => $source,
        "engine" => $engine,
        "success" => is_array($extracted),
        "duration_ms" => isset($context["duration_ms"])
            ? (int)$context["duration_ms"]
            : (int)round((microtime(true) - $startedAt) * 1000),
        "source_file_name" => $context["source_file_name"] ?? null,
        "input_kind" => $context["input_kind"] ?? null,
        "input_chars" => $textLen > 0 ? $textLen : null,
        "fields_filled" => nlp_filled_fields($extracted),
        "clause_count" => count($clauses),
        "avg_confidence" => $avg,
        "error_message" => $context["error_message"] ?? null,
    ]);
}
