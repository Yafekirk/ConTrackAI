<?php
declare(strict_types=1);

require_once __DIR__ . "/../api/lib_python_nlp.php";

$files = [
    "lease-01-ballroom-venue.pdf",
    "lease-02-event-transport.pdf",
    "lease-03-prop-warehouse.pdf",
    "service-01-catering.pdf",
    "service-02-event-security.pdf",
    "service-03-production-crew.pdf",
];

$keys = [
    "contract_title",
    "contract_type",
    "client_name",
    "vendor_name",
    "start_date",
    "end_date",
    "contract_value",
    "payment_terms",
];

foreach ($files as $name) {
    $path = __DIR__ . "/../samples/demo-contract-pack/" . $name;
    $extracted = python_nlp_extract("", $path);
    $encoded = json_encode(["extracted" => $extracted]);
    $jsonErr = json_last_error();
    $jsonMsg = json_last_error_msg();
    echo "==== {$name}\n";
    if ($extracted === null) {
        echo "PHP python_nlp_extract returned NULL\n";
        continue;
    }
    $empty = [];
    foreach ($keys as $key) {
        $val = $extracted[$key] ?? null;
        if ($val === null || $val === "") {
            $empty[] = $key;
        }
    }
    echo "engine=" . ($extracted["engine"] ?? "?") . " title=" . ($extracted["contract_title"] ?? "") . "\n";
    echo "empty=[" . implode(",", $empty) . "] json_ok=" . ($encoded === false ? "NO" : "yes") . " json_err={$jsonErr} {$jsonMsg} bytes=" . strlen((string)$encoded) . "\n";
}
