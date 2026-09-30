<?php
declare(strict_types=1);
require_once dirname(__DIR__) . "/api/lib_vendor_score.php";

$criteria = [
    ["id" => 1, "weight" => 0.2, "name" => "A"],
    ["id" => 2, "weight" => 0.3, "name" => "B"],
    ["id" => 3, "weight" => 0.5, "name" => "C"],
];

$ok = vendor_score_calculate($criteria, [1 => 80, 2 => 60, 3 => 100]);
// 80*0.2 + 60*0.3 + 100*0.5 = 16 + 18 + 50 = 84
if (!$ok["ok"] || $ok["overall"] !== 84.0) {
    fwrite(STDERR, "expected 84 got " . json_encode($ok) . PHP_EOL);
    exit(1);
}
if (vendor_score_rating(84.0) !== "good") {
    fwrite(STDERR, "rating failed\n");
    exit(1);
}
$missing = vendor_score_calculate($criteria, [1 => 80, 2 => 60]);
if ($missing["ok"] || stripos((string)$missing["error"], "required") === false) {
    fwrite(STDERR, "missing score not rejected\n");
    exit(1);
}
$oor = vendor_score_calculate($criteria, [1 => 101, 2 => 60, 3 => 10]);
if ($oor["ok"]) {
    fwrite(STDERR, "out of range not rejected\n");
    exit(1);
}
if (vendor_star_rating(85) !== 4.4 || vendor_star_rating(100) !== 5.0 || vendor_star_rating(0) !== 1.0) {
    fwrite(STDERR, "star rating failed " . vendor_star_rating(85) . "\n");
    exit(1);
}
echo "vendor_score_calculate ok\n";
