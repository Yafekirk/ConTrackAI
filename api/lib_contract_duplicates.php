<?php
declare(strict_types=1);

require_once __DIR__ . "/lib_contract_pdf.php";

/**
 * Duplicate submission detection for vendor contracts.
 * A submission is a duplicate when its PDF is byte-identical to a stored PDF of the same vendor,
 * or when every key contract term matches one of that vendor's existing (non-archived) contracts.
 */

const CONTRACT_DUPLICATE_TERM_KEYS = [
    "contract_title",
    "contract_type",
    "contract_value",
    "start_date",
    "end_date",
    "payment_terms",
    "scope_of_work",
    "renewal_terms",
    "penalty_clause",
];

function contract_duplicate_normalize_text($value): string
{
    $text = strtolower(trim((string)($value ?? "")));
    return (string)preg_replace('/\s+/', " ", $text);
}

/** @param array<string, mixed> $terms */
function contract_duplicate_terms_key(array $terms): string
{
    $parts = [];
    foreach (CONTRACT_DUPLICATE_TERM_KEYS as $key) {
        $raw = $terms[$key] ?? null;
        if ($key === "contract_value") {
            $parts[] = number_format((float)($raw ?? 0), 2, ".", "");
        } elseif ($key === "start_date" || $key === "end_date") {
            $parts[] = substr(trim((string)($raw ?? "")), 0, 10);
        } else {
            $parts[] = contract_duplicate_normalize_text($raw);
        }
    }
    return implode("\x1F", $parts);
}

/** @return array<int, array<string, mixed>> */
function contract_duplicate_vendor_rows($vendorId): array
{
    if ($vendorId === null || $vendorId === "") {
        return [];
    }
    $vendorEnc = urlencode((string)$vendorId);
    $select = "id,contract_code,status,is_archived," . implode(",", CONTRACT_DUPLICATE_TERM_KEYS);
    $result = supabase_request("GET", "vendor_contracts?vendor_id=eq.{$vendorEnc}&select={$select}");
    if (!$result["ok"]) {
        // Older schemas may lack contract_code / is_archived / NLP columns.
        $result = supabase_request(
            "GET",
            "vendor_contracts?vendor_id=eq.{$vendorEnc}&select=id,status,contract_title,contract_type,contract_value,start_date,end_date,payment_terms,scope_of_work"
        );
    }
    if (!$result["ok"] || !is_array($result["data"])) {
        return [];
    }
    return array_values(array_filter(
        $result["data"],
        static fn($row): bool => is_array($row) && empty($row["is_archived"])
    ));
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @return array<string, mixed>|null
 */
function contract_duplicate_match_pdf(array $rows, string $pdfSha256, ?int $pdfSize = null): ?array
{
    $pdfSha256 = strtolower(trim($pdfSha256));
    if (!preg_match('/^[a-f0-9]{64}$/', $pdfSha256)) {
        return null;
    }
    foreach ($rows as $row) {
        $id = (string)($row["id"] ?? "");
        if ($id === "" || !contract_pdf_file_exists($id)) {
            continue;
        }
        $path = contract_pdf_absolute_path($id);
        if ($pdfSize !== null && @filesize($path) !== $pdfSize) {
            continue;
        }
        $storedHash = @hash_file("sha256", $path);
        if (is_string($storedHash) && hash_equals($storedHash, $pdfSha256)) {
            return $row;
        }
    }
    return null;
}

/**
 * @param array<int, array<string, mixed>> $rows
 * @param array<string, mixed> $terms
 * @return array<string, mixed>|null
 */
function contract_duplicate_match_terms(array $rows, array $terms): ?array
{
    $wanted = contract_duplicate_terms_key($terms);
    foreach ($rows as $row) {
        if (contract_duplicate_terms_key($row) === $wanted) {
            return $row;
        }
    }
    return null;
}

/** @param array<string, mixed> $row */
function contract_duplicate_summary(array $row): array
{
    return [
        "id" => $row["id"] ?? null,
        "contract_code" => $row["contract_code"] ?? null,
        "contract_title" => $row["contract_title"] ?? null,
        "status" => $row["status"] ?? null,
    ];
}

/**
 * @param array<string, mixed> $row
 * @param string $reason "pdf" when the file is identical, "terms" when every key term matches
 */
function contract_duplicate_message(array $row, string $reason): string
{
    $code = trim((string)($row["contract_code"] ?? ""));
    $title = trim((string)($row["contract_title"] ?? ""));
    $status = trim((string)($row["status"] ?? ""));
    $label = $title !== "" ? "\"{$title}\"" : "an existing contract";
    if ($code !== "") {
        $label .= " ({$code})";
    }
    if ($status !== "") {
        $label .= ", status: {$status}";
    }
    $what = $reason === "pdf"
        ? "This PDF has already been submitted as {$label}."
        : "A contract with the same terms has already been submitted as {$label}.";
    return $what . " Make a change to the contract before submitting it again.";
}
