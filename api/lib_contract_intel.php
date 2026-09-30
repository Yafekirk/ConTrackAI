<?php
declare(strict_types=1);

/**
 * Contract intelligence: NLP extraction, classification, status monitoring,
 * archive rules, payment-term formatting, and vendor scoring.
 */

function normalize_extracted_date(string $raw): ?string
{
    $raw = trim($raw);
    if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/', $raw)) {
        return $raw;
    }
    if (preg_match('#^([0-9]{1,2})/([0-9]{1,2})/([0-9]{4})$#', $raw, $m)) {
        return sprintf("%04d-%02d-%02d", (int)$m[3], (int)$m[1], (int)$m[2]);
    }
    $ts = strtotime($raw);
    if ($ts !== false) {
        return date("Y-m-d", $ts);
    }
    return null;
}

/**
 * Display payment terms as duration labels: "Net 30" → "30 Days".
 */
function format_payment_terms_duration(?string $raw): string
{
    $raw = trim((string)$raw);
    if ($raw === "") {
        return "";
    }
    $lower = strtolower($raw);
    if (preg_match('/\bnet\s*(\d{1,3})\b/i', $raw, $m)) {
        return ((int)$m[1]) . " Days";
    }
    if (preg_match('/\b(\d{1,3})\s*days?\b/i', $raw, $m)) {
        return ((int)$m[1]) . " Days";
    }
    if (str_contains($lower, "milestone")) {
        return "Milestone-based";
    }
    if (str_contains($lower, "upfront") || str_contains($lower, "due on receipt") || str_contains($lower, "advance")) {
        return "Upfront";
    }
    if (str_contains($lower, "monthly")) {
        return "Monthly";
    }
    if (str_contains($lower, "quarter")) {
        return "Quarterly";
    }
    return $raw;
}

function extract_labeled_line(string $text, string $compact, string $pattern): ?string
{
    if (preg_match($pattern, $text, $m)) {
        $val = trim(preg_replace("/\s+/u", " ", $m[1]));
        $val = trim(preg_replace(
            '/\s+(RECITALS|WHEREAS|NOW,\s*THEREFORE|CONTRACT TYPE|EFFECTIVE DATE|START DATE|END DATE|CONTRACT VALUE|SCOPE OF WORK|PAYMENT TERMS|CLIENT NAME|VENDOR NAME|VENDOR ADDRESS|PENALTY CLAUSE|RENEWAL TERMS|TERMINATION CLAUSE|SIGNED DATE|DETAILED INVOICING|SERVICE LEVELS|CONFIDENTIALITY)\s*:.*/isu',
            "",
            $val
        ));
        return $val !== "" ? $val : null;
    }
    if (preg_match($pattern, $compact, $m)) {
        $val = trim(preg_replace("/\s+/u", " ", $m[1]));
        return $val !== "" ? $val : null;
    }
    return null;
}

function extract_contract_fields(string $text): array
{
    $result = [
        "contract_title" => null,
        "contract_type" => null,
        "contract_value" => null,
        "start_date" => null,
        "end_date" => null,
        "payment_terms" => null,
        "scope" => null,
        "renewal_terms" => null,
        "penalty_clause" => null,
        "financial_obligations" => null,
        "classification" => null,
        "client_name" => null,
        "vendor_name" => null,
        "vendor_address" => null,
        "currency" => null,
        "termination_clause" => null,
        "client_signatory" => null,
        "vendor_signatory" => null,
        "signed_date" => null,
    ];

    if ($text === "") {
        return $result;
    }

    $compact = preg_replace("/\s+/u", " ", $text);

    if (preg_match('/(?:contract title|agreement title|title of agreement)\s*[:\-]\s*([^\n\r]+)/iu', $text, $m)) {
        $t = trim(preg_replace(
            '/\s+(RECITALS|WHEREAS|CONTRACT TYPE|EFFECTIVE DATE|START DATE|END DATE|CONTRACT VALUE|CLIENT:|VENDOR:|THIS AGREEMENT)\b.*/isu',
            "",
            trim($m[1], " \t\-–—")
        ));
        $result["contract_title"] = $t !== "" ? $t : null;
    }
    if (empty($result["contract_title"]) && preg_match(
        '/(?:contract title|agreement title|title of agreement)\s*[:\-]\s*(.+?)(?=\s+(?:CONTRACT TYPE|EFFECTIVE DATE|START DATE|END DATE|CONTRACT VALUE|RECITALS|WHEREAS|PARTIES|CLIENT:|VENDOR:)|$)/iu',
        $compact,
        $m
    )) {
        $result["contract_title"] = trim($m[1], " \t\r\n\-–—");
    }
    if (empty($result["contract_title"]) && preg_match('/(?:contract title|title)\s*[:\-]\s*(.+)/i', $text, $m)) {
        $result["contract_title"] = trim($m[1]);
    }

    if (preg_match('/(?:contract type|type of contract|agreement type)\s*[:\-]\s*([^\n\r]+)/iu', $text, $m)) {
        $rawType = trim($m[1]);
        $result["contract_type"] = trim(preg_replace(
            '/\s+(RECITALS|WHEREAS|NOW,\s*THEREFORE|EFFECTIVE|CONTRACT VALUE|SCOPE|PAYMENT|CLIENT|VENDOR)\b.*/isu',
            "",
            $rawType
        ));
    } elseif (preg_match('/(?:contract type|type)\s*[:\-]\s*([^\n\r]+)/iu', $text, $m)) {
        $result["contract_type"] = trim(preg_replace(
            '/\s+(RECITALS|WHEREAS|NOW,\s*THEREFORE|EFFECTIVE|CONTRACT VALUE)\b.*/isu',
            "",
            trim($m[1])
        ));
    }

    if (preg_match('/(?:total\s+(?:contract\s+)?value|contract\s+value|amount)\s*[:\-]\s*(?:PHP|USD|EUR|PHP\s*|₱|\$)?\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/iu', $compact, $m)) {
        $result["contract_value"] = (float)str_replace(",", "", $m[1]);
    } elseif (preg_match('/₱\s*([0-9][0-9,]*(?:\.[0-9]{1,2})?)/u', $text, $m)) {
        $result["contract_value"] = (float)str_replace(",", "", $m[1]);
    }

    $datePat = '([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{1,2}\/[0-9]{1,2}\/[0-9]{2,4}|(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+[0-9]{1,2},?\s+[0-9]{4})';
    $datePatLoose = '([0-9]{4})\s*[-–]\s*([0-9]{1,2})\s*[-–]\s*([0-9]{1,2})';
    if (preg_match('/(?:start date|effective date|commencement)\s*[:\-]?\s*' . $datePat . '/iu', $text, $m)) {
        $result["start_date"] = normalize_extracted_date($m[1]);
    } elseif (preg_match('/(?:start date|effective date|commencement)\s*[:\-]?\s*' . $datePatLoose . '/iu', $compact, $m)) {
        $result["start_date"] = sprintf("%04d-%02d-%02d", (int)$m[1], (int)$m[2], (int)$m[3]);
    } elseif (preg_match('/(?:start date|effective date|commencement)\s*[:\-]?\s*' . $datePat . '/iu', $compact, $m)) {
        $result["start_date"] = normalize_extracted_date($m[1]);
    }

    if (preg_match('/(?:end date|expiry|expiration|termination date)\s*[:\-]?\s*' . $datePat . '/iu', $text, $m)) {
        $result["end_date"] = normalize_extracted_date($m[1]);
    } elseif (preg_match('/(?:end date|expiry|expiration|termination date)\s*[:\-]?\s*' . $datePatLoose . '/iu', $compact, $m)) {
        $result["end_date"] = sprintf("%04d-%02d-%02d", (int)$m[1], (int)$m[2], (int)$m[3]);
    } elseif (preg_match('/(?:end date|expiry|expiration|termination date)\s*[:\-]?\s*' . $datePat . '/iu', $compact, $m)) {
        $result["end_date"] = normalize_extracted_date($m[1]);
    }

    if (preg_match('/(?:payment terms?|billing|invoicing)\s*[:\-]\s*(.+?)(?=\n\s*\n|\n[A-Z][^\n]{2,30}:|$)/isu', $text, $m)) {
        $result["payment_terms"] = trim(preg_replace("/\s+/u", " ", $m[1]));
    } elseif (preg_match('/(?:payment terms?)\s*[:\-]\s*([^\n\r]+)/iu', $text, $m)) {
        $rawPay = trim(preg_replace(
            '/\s+(Detailed|SERVICE|CONFIDENTIALITY|INSURANCE|PENALTY|RENEWAL)\b.*/isu',
            "",
            trim($m[1])
        ));
        $result["payment_terms"] = trim(preg_replace("/\s+/u", " ", $rawPay));
    }

    if (preg_match('/(?:scope of work|scope|statement of work|sow|description)\s*[:\-]\s*(.+?)(?=\n\s*\n|\n[A-Z][^\n]{2,30}:|$)/isu', $text, $m)) {
        $result["scope"] = trim(preg_replace("/\s+/u", " ", $m[1]));
    } elseif (preg_match('/(?:scope|scope of work|description)\s*[:\-]\s*(.+)/i', $text, $m)) {
        $result["scope"] = trim($m[1]);
    }

    $renewal = extract_labeled_line(
        $text,
        $compact,
        '/(?:renewal terms?|auto[- ]?renew(?:al)?(?:\s+terms?)?|renewal clause)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    if ($renewal === null && preg_match('/\b(auto[- ]?renew(?:s|al)?(?:\s+for\s+\d+\s+months?)?|manual renewal only|no renewal)\b/iu', $compact, $m)) {
        $renewal = trim($m[1]);
    }
    $result["renewal_terms"] = $renewal;

    $penalty = extract_labeled_line(
        $text,
        $compact,
        '/(?:penalty(?:\s+clause)?|liquidated damages|late(?:\s+payment)?\s+(?:fee|penalty)|penalties)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    if ($penalty === null && preg_match('/(?:penalty|liquidated damages).{0,40}?([0-9]+(?:\.[0-9]+)?\s*%[^\n\r]*)/iu', $compact, $m)) {
        $penalty = trim($m[0]);
    }
    $result["penalty_clause"] = $penalty;

    $result["client_name"] = extract_labeled_line(
        $text,
        $compact,
        '/(?:client name)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    $result["vendor_name"] = extract_labeled_line(
        $text,
        $compact,
        '/(?:vendor name)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    $result["vendor_address"] = extract_labeled_line(
        $text,
        $compact,
        '/(?:vendor address)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    $currencyLine = extract_labeled_line(
        $text,
        $compact,
        '/(?:currency)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    if ($currencyLine && preg_match('/\b(PHP|USD|EUR)\b/i', $currencyLine, $cm)) {
        $result["currency"] = strtoupper($cm[1]);
    } elseif (preg_match('/Contract Value:\s*(PHP|USD|EUR)/i', $text, $cm)) {
        $result["currency"] = strtoupper($cm[1]);
    }
    $result["termination_clause"] = extract_labeled_line(
        $text,
        $compact,
        '/(?:termination clause)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    $result["client_signatory"] = extract_labeled_line(
        $text,
        $compact,
        '/(?:client authorized signatory|signatory\s*\(?client\)?)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    $result["vendor_signatory"] = extract_labeled_line(
        $text,
        $compact,
        '/(?:vendor authorized signatory|signatory\s*\(?vendor\)?)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    if (preg_match('/(?:signed date)\s*[:\-]?\s*' . $datePat . '/iu', $text, $m)) {
        $result["signed_date"] = normalize_extracted_date($m[1]);
    } elseif (preg_match('/(?:signed date)\s*[:\-]?\s*' . $datePat . '/iu', $compact, $m)) {
        $result["signed_date"] = normalize_extracted_date($m[1]);
    }

    $fin = extract_labeled_line(
        $text,
        $compact,
        '/(?:financial obligations?|total obligation|consideration)\s*[:\-]\s*([^\n\r]+)/iu'
    );
    $parts = [];
    if (!empty($result["contract_value"])) {
        $ccy = $result["currency"] ?: "PHP";
        $parts[] = "Contract value " . $ccy . " " . number_format((float)$result["contract_value"], 2);
    }
    if (!empty($result["payment_terms"])) {
        $parts[] = "Payment: " . format_payment_terms_duration((string)$result["payment_terms"]);
    }
    if ($penalty) {
        $parts[] = "Penalties: " . $penalty;
    }
    if ($fin) {
        array_unshift($parts, $fin);
    }
    $result["financial_obligations"] = $parts !== [] ? implode("; ", $parts) : $fin;

    if (!empty($result["payment_terms"])) {
        $result["payment_terms"] = format_payment_terms_duration((string)$result["payment_terms"]);
    }

    $classified = classify_contract_record($result, (float)($result["contract_value"] ?? 0));
    $result["classification"] = $classified["classification"];
    $result["risk"] = $classified["risk"];
    $result["risk_score"] = $classified["risk_score"];

    return $result;
}

function classify_contract_record(array $fields, float $value): array
{
    $type = strtolower((string)($fields["contract_type"] ?? ""));
    $class = "Other";
    if (str_contains($type, "supply")) {
        $class = "Supply Agreement";
    } elseif (str_contains($type, "consult")) {
        $class = "Consulting Contract";
    } elseif (str_contains($type, "maintain")) {
        $class = "Maintenance Contract";
    } elseif (str_contains($type, "lease")) {
        $class = "Lease Agreement";
    } elseif (str_contains($type, "service")) {
        $class = "Service Agreement";
    } elseif ($type !== "") {
        $class = trim((string)$fields["contract_type"]);
    }

    $penalty = strtolower((string)($fields["penalty_clause"] ?? ""));
    $riskScore = 18;
    $risk = "low";
    if ($value >= 5000000 || str_contains($penalty, "terminat") || preg_match('/[5-9][0-9]\s*%/', $penalty)) {
        $risk = "high";
        $riskScore = 82;
    } elseif ($value >= 1000000 || str_contains($penalty, "liquidat") || preg_match('/[1-9][0-9]?\s*%/', $penalty)) {
        $risk = "medium";
        $riskScore = 48;
    } elseif ($value >= 250000) {
        $risk = "low";
        $riskScore = 28;
    }
    return [
        "classification" => $class,
        "risk" => $risk,
        "risk_score" => $riskScore,
    ];
}

function unpack_nlp_meta(?string $submissionText): array
{
    $text = (string)$submissionText;
    if ($text !== "" && preg_match('/^CONTRACK_NLP:(\{.*\})\s*(?:\n---\n)?/s', $text, $m)) {
        $decoded = json_decode($m[1], true);
        return is_array($decoded) ? $decoded : [];
    }
    return [];
}

function pack_submission_text(string $text, array $nlp): string
{
    $meta = [
        "renewal_terms" => $nlp["renewal_terms"] ?? null,
        "penalty_clause" => $nlp["penalty_clause"] ?? null,
        "financial_obligations" => $nlp["financial_obligations"] ?? null,
        "classification" => $nlp["classification"] ?? null,
        "risk" => $nlp["risk"] ?? null,
        "risk_score" => $nlp["risk_score"] ?? null,
        "payment_terms" => $nlp["payment_terms"] ?? null,
        "start_date" => $nlp["start_date"] ?? null,
        "end_date" => $nlp["end_date"] ?? null,
        "client_name" => $nlp["client_name"] ?? null,
        "vendor_name" => $nlp["vendor_name"] ?? null,
        "vendor_address" => $nlp["vendor_address"] ?? null,
        "currency" => $nlp["currency"] ?? null,
        "termination_clause" => $nlp["termination_clause"] ?? null,
        "client_signatory" => $nlp["client_signatory"] ?? null,
        "vendor_signatory" => $nlp["vendor_signatory"] ?? null,
        "signed_date" => $nlp["signed_date"] ?? null,
    ];
    return "CONTRACK_NLP:" . json_encode($meta, JSON_UNESCAPED_UNICODE) . "\n---\n" . $text;
}

function strip_packed_submission_text(?string $text): string
{
    $raw = (string)$text;
    if (preg_match('/^CONTRACK_NLP:\{.*\}\s*\n---\n(.*)$/s', $raw, $m)) {
        return $m[1];
    }
    return $raw;
}

function days_until_end(?string $endRaw): ?int
{
    if ($endRaw === null || $endRaw === "") {
        return null;
    }
    try {
        $today = (new DateTimeImmutable("today"))->setTime(0, 0, 0);
        $end = (new DateTimeImmutable((string)$endRaw))->setTime(0, 0, 0);
        return (int)$today->diff($end)->format("%r%a");
    } catch (Exception $e) {
        return null;
    }
}

function monitor_contract_row(array $row): array
{
    $status = strtolower(trim((string)($row["status"] ?? "pending")));
    $days = days_until_end(isset($row["end_date"]) ? (string)$row["end_date"] : null);
    $explicitArchive = !empty($row["is_archived"]) || !empty($row["archived_at"]);

    $monitor = $status !== "" ? $status : "pending";
    $renewalDue = false;
    $archived = $explicitArchive;

    if ($status === "approved") {
        if ($days !== null && $days < 0) {
            $monitor = "expired";
            $archived = true;
        } elseif ($days !== null && $days <= 30) {
            $monitor = "expiring";
            $renewalDue = true;
        } elseif ($days !== null && $days <= 90) {
            $monitor = "active";
            $renewalDue = true;
        } else {
            $monitor = "active";
        }
    } elseif ($status === "terminated") {
        $monitor = "terminated";
        $archived = true;
    } elseif ($status === "rejected") {
        $monitor = "rejected";
        $archived = true;
    } elseif ($status === "modification") {
        $monitor = "modification";
    } elseif ($status === "renegotiation") {
        $monitor = "renewal";
        $renewalDue = true;
    } elseif ($status === "archived") {
        $monitor = "archived";
        $archived = true;
    }

    $renewalText = strtolower((string)($row["renewal_terms"] ?? ""));
    if ($status === "approved" && $days !== null && $days >= 0 && $days <= 90) {
        $renewalDue = true;
    }
    if (str_contains($renewalText, "auto") && $status === "approved" && $days !== null && $days <= 120) {
        $renewalDue = true;
    }

    return [
        "monitor_status" => $monitor,
        "is_archived" => $archived,
        "days_until_end" => $days,
        "renewal_due" => $renewalDue,
    ];
}

function enrich_contract_row(array $row): array
{
    $meta = unpack_nlp_meta($row["submission_text"] ?? null);
    foreach ([
        "renewal_terms", "penalty_clause", "financial_obligations", "classification", "risk", "risk_score",
        "client_name", "vendor_address", "currency", "termination_clause",
        "client_signatory", "vendor_signatory", "signed_date",
    ] as $key) {
        if ((!isset($row[$key]) || $row[$key] === null || $row[$key] === "") && isset($meta[$key]) && $meta[$key] !== null && $meta[$key] !== "") {
            $row[$key] = $meta[$key];
        }
    }

    $row["payment_terms"] = format_payment_terms_duration((string)($row["payment_terms"] ?? ($meta["payment_terms"] ?? "")));
    $classified = classify_contract_record($row, (float)($row["contract_value"] ?? 0));
    if (empty($row["classification"])) {
        $row["classification"] = $classified["classification"];
    }
    if (empty($row["risk"])) {
        $row["risk"] = $classified["risk"];
    }
    if (!isset($row["risk_score"]) || $row["risk_score"] === "" || $row["risk_score"] === null) {
        $row["risk_score"] = $classified["risk_score"];
    }

    $mon = monitor_contract_row($row);
    $row["monitor_status"] = $mon["monitor_status"];
    $row["is_archived"] = $mon["is_archived"];
    $row["days_until_end"] = $mon["days_until_end"];
    $row["renewal_due"] = $mon["renewal_due"];
    $row["contract_text"] = strip_packed_submission_text($row["submission_text"] ?? null);

    if (empty($row["financial_obligations"])) {
        $bits = [];
        if (!empty($row["contract_value"])) {
            $bits[] = "PHP " . number_format((float)$row["contract_value"], 2);
        }
        if (!empty($row["payment_terms"])) {
            $bits[] = "Payment terms: " . $row["payment_terms"];
        }
        if (!empty($row["penalty_clause"])) {
            $bits[] = "Penalties: " . $row["penalty_clause"];
        }
        $row["financial_obligations"] = $bits !== [] ? implode("; ", $bits) : null;
    }

    return $row;
}

function enrich_contract_rows(array $rows): array
{
    $out = [];
    foreach ($rows as $row) {
        if (is_array($row)) {
            $out[] = enrich_contract_row($row);
        }
    }
    return $out;
}

function vendor_score_from_counts(int $approved, int $pending, int $rejected, int $total): int
{
    if ($total === 0) {
        return 0;
    }
    return (int)round((($approved * 100) + ($pending * 60) + ($rejected * 30)) / max($total, 1));
}

function vendor_recommendations(int $score, string $risk, int $rejected, bool $renewalDue): array
{
    $recs = [];
    if ($score >= 80) {
        $recs[] = "Maintain current delivery and documentation standards.";
        $recs[] = "Eligible for priority renewal and expanded scope.";
    } elseif ($score >= 60) {
        $recs[] = "Improve on-time delivery and keep contract files complete.";
        $recs[] = "Address outstanding pending items before the next review cycle.";
    } else {
        $recs[] = "Performance is below target — schedule a compliance review.";
        $recs[] = "Reduce rejected submissions by validating dates, value, and PDF quality before upload.";
    }
    if ($risk === "high") {
        $recs[] = "High financial/penalty exposure — require additional CEO oversight on new awards.";
    }
    if ($rejected > 0) {
        $recs[] = "Resolve rejected-contract findings before submitting similar agreements.";
    }
    if ($renewalDue) {
        $recs[] = "Start renewal talks now; one or more contracts expire within 90 days.";
    }
    return $recs;
}

function build_vendor_metrics(array $contracts): array
{
    $total = count($contracts);
    $approved = 0;
    $pending = 0;
    $rejected = 0;
    $renewalDue = false;
    $maxVal = 0.0;
    foreach ($contracts as $c) {
        $st = strtolower((string)($c["status"] ?? ""));
        if ($st === "approved") {
            $approved++;
        } elseif ($st === "pending") {
            $pending++;
        } elseif ($st === "rejected") {
            $rejected++;
        }
        $maxVal = max($maxVal, (float)($c["contract_value"] ?? 0));
        $enr = enrich_contract_row($c);
        if (!empty($enr["renewal_due"])) {
            $renewalDue = true;
        }
    }

    // Vendors with no contracts have no contract-risk metrics.
    if ($total === 0) {
        return [
            "overall_score" => 0,
            "risk" => "low",
            "risk_score" => 0,
            "recommendations" => [],
            "totals" => [
                "total_contracts" => 0,
                "approved" => 0,
                "pending" => 0,
                "rejected" => 0,
            ],
            "renewal_due" => false,
            "breakdown" => [
                "delivery" => 0,
                "quality" => 0,
                "pricing" => 0,
                "compliance" => 0,
                "communication" => 0,
                "innovation" => 0,
            ],
        ];
    }

    $overall = vendor_score_from_counts($approved, $pending, $rejected, $total);
    $classified = classify_contract_record(["contract_value" => $maxVal], $maxVal);
    $risk = $classified["risk"];
    if ($rejected > 0 && $total > 0 && ($rejected / $total) >= 0.4) {
        $risk = "high";
    } elseif ($overall < 60 && $risk === "low") {
        $risk = "medium";
    }
    $riskScore = min(100, max(5, 100 - $overall + ($risk === "high" ? 25 : ($risk === "medium" ? 10 : 0))));
    return [
        "overall_score" => $overall,
        "risk" => $risk,
        "risk_score" => $riskScore,
        "recommendations" => vendor_recommendations($overall, $risk, $rejected, $renewalDue),
        "totals" => [
            "total_contracts" => $total,
            "approved" => $approved,
            "pending" => $pending,
            "rejected" => $rejected,
        ],
        "renewal_due" => $renewalDue,
        "breakdown" => [
            "delivery" => 0,
            "quality" => 0,
            "pricing" => 0,
            "compliance" => 0,
            "communication" => 0,
            "innovation" => 0,
        ],
    ];
}

function renewal_notice_for_row(array $row): ?array
{
    $row = enrich_contract_row($row);
    if (empty($row["renewal_due"])) {
        return null;
    }
    $days = $row["days_until_end"];
    $when = $days === null ? "soon" : ($days < 0 ? "expired" : "in {$days} day(s)");
    return [
        "id" => $row["id"] ?? null,
        "type" => ($days !== null && $days <= 30) ? "warning" : "info",
        "title" => "Renewal: " . (string)($row["contract_title"] ?? "Contract"),
        "desc" => "Subject for renewal {$when}. Terms: " . (string)($row["renewal_terms"] ?: "review end date"),
        "time" => $row["end_date"] ?? "",
        "unread" => true,
        "kind" => "renewal",
        "archived" => !empty($row["is_archived"]),
    ];
}
