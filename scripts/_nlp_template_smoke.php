<?php
declare(strict_types=1);
require_once __DIR__ . "/../api/lib_contract_intel.php";

$path = __DIR__ . "/../samples/template-style-north-luzon-cold-storage-lease.txt";
$text = file_get_contents($path);
if ($text === false) {
    fwrite(STDERR, "Missing sample: {$path}\n");
    exit(1);
}

$result = extract_contract_fields($text);
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;

$required = [
    "contract_title",
    "contract_type",
    "start_date",
    "end_date",
    "client_name",
    "vendor_name",
    "vendor_address",
    "scope",
    "contract_value",
    "currency",
    "payment_terms",
    "penalty_clause",
    "renewal_terms",
    "termination_clause",
    "client_signatory",
    "vendor_signatory",
    "signed_date",
];
$missing = [];
foreach ($required as $key) {
    if ($result[$key] === null || $result[$key] === "") {
        $missing[] = $key;
    }
}
if ($missing) {
    fwrite(STDERR, "Missing NLP fields: " . implode(", ", $missing) . PHP_EOL);
    exit(1);
}

echo "NLP sample extraction OK\n";
