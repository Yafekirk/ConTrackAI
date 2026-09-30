<?php
declare(strict_types=1);

/**
 * Build 9 template-aligned demo contracts (3 supply, 3 lease, 3 service).
 * Run: php scripts/publish_demo_contract_pack.php
 */

$root = dirname(__DIR__);
$outDir = $root . "/samples/demo-contract-pack";
if (!is_dir($outDir) && !mkdir($outDir, 0777, true) && !is_dir($outDir)) {
    fwrite(STDERR, "Cannot create {$outDir}\n");
    exit(1);
}

foreach (glob($outDir . "/*.pdf") ?: [] as $old) {
    @unlink($old);
}
foreach (glob($outDir . "/*.txt") ?: [] as $old) {
    @unlink($old);
}
foreach (glob($outDir . "/*.zip") ?: [] as $old) {
    @unlink($old);
}

$contracts = [
    [
        "file" => "supply-01-event-furnishings",
        "title" => "Event Furniture and Staging Supply Agreement 2026",
        "type" => "Supply Agreement",
        "effective" => "2026-05-15",
        "end" => "2027-05-14",
        "client" => "JBC Events Management",
        "vendor" => "Grandeur Event Furnishings Co.",
        "vendor_address" => "88 Katipunan Avenue, Loyola Heights, Quezon City, Metro Manila, Philippines",
        "scope" => "Grandeur Event Furnishings Co. shall supply banquet tables, Chiavari chairs, cocktail bars, stage platforms, and pipe-and-drape for JBC Events Management productions in Metro Manila. Deliveries follow the approved event calendar in three batches, with itemized inventories, on-site set-up for the first batch, and haul-out within twelve hours after each event.",
        "value" => "1,680,000.00",
        "currency" => "PHP",
        "payment" => "Net 30",
        "penalty" => "Late delivery beyond 7 calendar days incurs a 1% deduction per week from the affected batch value.",
        "renewal" => "Manual renewal only, subject to performance evaluation and mutual written agreement.",
        "termination" => "Either party may terminate with 30 days written notice for material breach not cured within 15 days from notice.",
        "client_signatory" => "Patricia J. Bernal, Managing Director, JBC Events Management",
        "vendor_signatory" => "Rafael D. Lim, General Manager, Grandeur Event Furnishings Co.",
        "signed" => "2026-05-10",
    ],
    [
        "file" => "supply-02-print-collaterals",
        "title" => "Event Print and Collateral Supply Agreement 2026",
        "type" => "Supply Agreement",
        "effective" => "2026-04-01",
        "end" => "2027-03-31",
        "client" => "JBC Events Management",
        "vendor" => "PrintHaus Creative Supplies Inc.",
        "vendor_address" => "14 Pioneer Street, Highway Hills, Mandaluyong City, Metro Manila, Philippines",
        "scope" => "PrintHaus Creative Supplies Inc. shall supply stage backdrops, pull-up banners, invitation kits, name plates, and program booklets for JBC Events Management client events. All lots shall match approved artwork, include a press proof, and replace damaged or misprinted pieces at Vendor cost before doors open.",
        "value" => "920,000.00",
        "currency" => "PHP",
        "payment" => "Net 15",
        "penalty" => "Failed delivery of a scheduled print run beyond 48 hours incurs a 2% deduction on that order value, plus rush reprint freight at Vendor expense.",
        "renewal" => "Auto-renew (12 months) unless either party gives 60 days written notice of non-renewal.",
        "termination" => "Either party may terminate for uncured material breach after 15 days written notice.",
        "client_signatory" => "Nina S. Domingo, Procurement Officer, JBC Events Management",
        "vendor_signatory" => "Andrea Mae Uy, Sales Director, PrintHaus Creative Supplies Inc.",
        "signed" => "2026-03-22",
    ],
    [
        "file" => "supply-03-av-equipment",
        "title" => "Audio Visual Equipment Supply Agreement 2026",
        "type" => "Supply Agreement",
        "effective" => "2026-06-01",
        "end" => "2027-05-31",
        "client" => "JBC Events Management",
        "vendor" => "LumenStage AV Trading Corp.",
        "vendor_address" => "210 Shaw Boulevard, Addition Hills, Mandaluyong City, Metro Manila, Philippines",
        "scope" => "LumenStage AV Trading Corp. shall supply LED wall panels, wireless microphones, digital mixers, spare lamps, and signal cables for JBC Events Management corporate and social events. Equipment shall be delivered, tested, and signed off in four milestone batches according to the production calendar, with serial-number inventories and a 24-hour replacement unit for any failed device.",
        "value" => "2,450,000.00",
        "currency" => "PHP",
        "payment" => "Milestone-based",
        "penalty" => "Each delayed milestone shipment beyond 5 calendar days incurs liquidated damages of 1.5% of that milestone value, capped at 10% of the contract value.",
        "renewal" => "No renewal. Any extension requires a written change order.",
        "termination" => "Either party may terminate for uncured material breach after 30 days written notice.",
        "client_signatory" => "Carlo M. Reyes, Operations Manager, JBC Events Management",
        "vendor_signatory" => "Kevin Paul Ong, Managing Partner, LumenStage AV Trading Corp.",
        "signed" => "2026-05-20",
    ],
    [
        "file" => "lease-01-ballroom-venue",
        "title" => "Grand Ballroom and Pre-Function Hall Lease Agreement 2026",
        "type" => "Lease Agreement",
        "effective" => "2026-07-01",
        "end" => "2028-06-30",
        "client" => "JBC Events Management",
        "vendor" => "Marquee Pavilion Venues Inc.",
        "vendor_address" => "The Marquee, 5th Avenue, Bonifacio Global City, Taguig City, Metro Manila, Philippines",
        "scope" => "Marquee Pavilion Venues Inc. shall lease to JBC Events Management the Grand Ballroom and adjoining pre-function hall (approximately 1,200 square meters) for contracted event dates, including house lighting, basic power, restrooms, and loading-bay access. Vendor shall provide 24x7 building security on event days, HVAC during call times, and a dedicated venue coordinator.",
        "value" => "4,200,000.00",
        "currency" => "PHP",
        "payment" => "Monthly",
        "penalty" => "Late rental payment beyond 5 calendar days incurs a 2% surcharge on the overdue monthly rent plus 0.1% per day thereafter until paid.",
        "renewal" => "Auto-renew (12 months) unless either party gives 90 days written notice of non-renewal.",
        "termination" => "Either party may terminate for uncured material breach after 30 days written notice. Client may terminate for convenience with 90 days written notice and payment of rent through the notice period.",
        "client_signatory" => "Patricia J. Bernal, Managing Director, JBC Events Management",
        "vendor_signatory" => "Isabelle G. Tan, Leasing Director, Marquee Pavilion Venues Inc.",
        "signed" => "2026-06-12",
    ],
    [
        "file" => "lease-02-event-transport",
        "title" => "Event Logistics Vehicle Lease Agreement 2026",
        "type" => "Lease Agreement",
        "effective" => "2026-05-01",
        "end" => "2027-04-30",
        "client" => "JBC Events Management",
        "vendor" => "RapidMove Event Transport Inc.",
        "vendor_address" => "15 West Service Road, Sucat, Paranaque City, Metro Manila, Philippines",
        "scope" => "RapidMove Event Transport Inc. shall lease eight (8) closed vans and two (2) passenger shuttles to JBC Events Management for decor, equipment, and staff transport on event days, including preventive maintenance, comprehensive insurance, roadside assistance, and a replacement unit within 24 hours of a breakdown.",
        "value" => "1,560,000.00",
        "currency" => "PHP",
        "payment" => "Monthly",
        "penalty" => "Unavailability of a contracted unit beyond 24 hours without a replacement incurs a 3% credit of that unit monthly lease fee per day of downtime.",
        "renewal" => "Manual renewal only after a fleet condition inspection and written agreement.",
        "termination" => "Either party may terminate for uncured material breach after 15 days written notice. Early return of units requires 30 days notice and a two-month residual fee.",
        "client_signatory" => "Carlo M. Reyes, Operations Manager, JBC Events Management",
        "vendor_signatory" => "Dominic R. Santos, Fleet Manager, RapidMove Event Transport Inc.",
        "signed" => "2026-04-18",
    ],
    [
        "file" => "lease-03-prop-warehouse",
        "title" => "Event Props and Production Warehouse Lease Agreement 2026",
        "type" => "Lease Agreement",
        "effective" => "2026-07-01",
        "end" => "2028-06-30",
        "client" => "JBC Events Management",
        "vendor" => "StowWell Logistics Corp.",
        "vendor_address" => "Lot 9, FTI Complex, Western Bicutan, Taguig City, Metro Manila, Philippines",
        "scope" => "StowWell Logistics Corp. shall lease to JBC Events Management approximately 800 square meters of dry warehouse space for chairs, linens, lighting cases, and leftover production materials, including dock access, 24x7 compound security, racking, and monthly inventory access logs. Vendor shall restore power and access within eight hours of a confirmed facility failure.",
        "value" => "2,160,000.00",
        "currency" => "PHP",
        "payment" => "Monthly",
        "penalty" => "Late rental payment beyond five calendar days incurs a 2% surcharge on the overdue monthly rent plus 0.1% per day thereafter until paid in full.",
        "renewal" => "Auto-renew (12 months) unless either party gives sixty days written notice of non-renewal.",
        "termination" => "Either party may terminate for uncured material breach after thirty days written notice. Client may terminate for convenience with ninety days written notice and payment of rent through the notice period.",
        "client_signatory" => "Nina S. Domingo, Procurement Officer, JBC Events Management",
        "vendor_signatory" => "Maricel A. Bautista, Property Manager, StowWell Logistics Corp.",
        "signed" => "2026-06-20",
    ],
    [
        "file" => "service-01-catering",
        "title" => "Banquet Catering Services Agreement 2026",
        "type" => "Service Agreement",
        "effective" => "2026-06-01",
        "end" => "2027-05-31",
        "client" => "JBC Events Management",
        "vendor" => "MesaNueva Catering Services Inc.",
        "vendor_address" => "42 Scout Torillo Street, South Triangle, Quezon City, Metro Manila, Philippines",
        "scope" => "MesaNueva Catering Services Inc. shall provide banquet menus, service staff, kitchen set-up, and post-event pull-out for JBC Events Management weddings, corporate dinners, and socials in Metro Manila. Coverage includes tasting sessions, on-site captain, dietary labeling, and a guest-count adjustment window of seventy-two hours before each event.",
        "value" => "3,150,000.00",
        "currency" => "PHP",
        "payment" => "Net 30",
        "penalty" => "A confirmed staffing shortfall or missed tasting more than twice in a month incurs a 5% service credit on that month fee.",
        "renewal" => "Auto-renew (12 months) unless either party gives 60 days written notice of non-renewal.",
        "termination" => "Either party may terminate for uncured material breach after 30 days written notice. Client may terminate for convenience with 90 days notice and payment through the notice period.",
        "client_signatory" => "Patricia J. Bernal, Managing Director, JBC Events Management",
        "vendor_signatory" => "Chef Lorenzo P. Villanueva, Principal, MesaNueva Catering Services Inc.",
        "signed" => "2026-05-28",
    ],
    [
        "file" => "service-02-event-security",
        "title" => "Event Security and Crowd Control Services Agreement 2026",
        "type" => "Service Agreement",
        "effective" => "2026-04-01",
        "end" => "2027-03-31",
        "client" => "JBC Events Management",
        "vendor" => "Nightwatch Event Security Inc.",
        "vendor_address" => "7 Pioneer Street, Highway Hills, Mandaluyong City, Metro Manila, Philippines",
        "scope" => "Nightwatch Event Security Inc. shall provide licensed security officers, bag checks, VIP access control, and incident reports for JBC Events Management events. Manning follows the posted table per event, with a supervisor on site, radio coordination with the JBC floor manager, and post-event turnover of lost-and-found and incident logs within twenty-four hours.",
        "value" => "1,440,000.00",
        "currency" => "PHP",
        "payment" => "Monthly",
        "penalty" => "An unfilled post beyond two hours without a qualified replacement incurs a 4% credit of that day post fee.",
        "renewal" => "Auto-renew (12 months) unless either party gives 45 days written notice of non-renewal.",
        "termination" => "Client may terminate immediately for loss of license or a proven integrity incident. Either party may terminate for uncured material breach after 15 days written notice.",
        "client_signatory" => "Carlo M. Reyes, Operations Manager, JBC Events Management",
        "vendor_signatory" => "Oscar Mendoza, Operations Vice President, Nightwatch Event Security Inc.",
        "signed" => "2026-03-20",
    ],
    [
        "file" => "service-03-production-crew",
        "title" => "Event Production and Technical Crew Services Agreement 2026",
        "type" => "Service Agreement",
        "effective" => "2026-08-01",
        "end" => "2027-07-31",
        "client" => "JBC Events Management",
        "vendor" => "Apex Production Crew Inc.",
        "vendor_address" => "3rd Floor, 6750 Building, Ayala Avenue, Makati City, Metro Manila, Philippines",
        "scope" => "Apex Production Crew Inc. shall provide technical direction, lighting operators, sound engineers, and stage managers for JBC Events Management shows. Crew call times follow the production rundown, including load-in, show call, and strike, with a show caller on the comms channel and a written show report after each event.",
        "value" => "2,280,000.00",
        "currency" => "PHP",
        "payment" => "Net 15",
        "penalty" => "A missed key-position call (technical director, sound, or lighting) without a qualified replacement incurs a 3% deduction on the event fee.",
        "renewal" => "Manual renewal only, subject to a season-end production review and written agreement.",
        "termination" => "Either party may terminate for uncured material breach after 15 days written notice. Client may terminate for convenience with 30 days notice.",
        "client_signatory" => "Nina S. Domingo, Procurement Officer, JBC Events Management",
        "vendor_signatory" => "Bianca R. Gomez, Production Director, Apex Production Crew Inc.",
        "signed" => "2026-07-15",
    ],
];

function contract_text(array $c): string
{
    return <<<TXT
Contract Template

1. Contract Header
- Contract Title: {$c["title"]}
- Contract Type: {$c["type"]}
- Effective Date: {$c["effective"]}
- End Date: {$c["end"]}

2. Parties
- Client Name: {$c["client"]}
- Vendor Name: {$c["vendor"]}
- Vendor Address: {$c["vendor_address"]}

3. Scope of Work
Scope of Work: {$c["scope"]}

4. Financial Terms
- Contract Value: {$c["currency"]} {$c["value"]}
- Currency: {$c["currency"]}
- Payment Terms: {$c["payment"]}
- Penalty Clause: {$c["penalty"]}

5. Renewal and Termination
- Renewal Terms: {$c["renewal"]}
- Termination Clause: {$c["termination"]}

6. Signatures
- Client Authorized Signatory: {$c["client_signatory"]}
- Vendor Authorized Signatory: {$c["vendor_signatory"]}
- Signed Date: {$c["signed"]}
TXT;
}

function pdf_escape(string $s): string
{
    return str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $s);
}

function wrap_line(string $line, int $max = 88): array
{
    $indent = "";
    if (str_starts_with($line, "- ")) {
        $indent = "  ";
    }
    $line = rtrim($line);
    if ($line === "") {
        return [""];
    }
    $words = preg_split("/\s+/", $line) ?: [];
    $rows = [];
    $cur = "";
    foreach ($words as $word) {
        $try = $cur === "" ? $word : $cur . " " . $word;
        if (strlen($try) > $max && $cur !== "") {
            $rows[] = $cur;
            $cur = $indent . $word;
        } else {
            $cur = $try;
        }
    }
    if ($cur !== "") {
        $rows[] = $cur;
    }
    return $rows !== [] ? $rows : [""];
}

function write_pdf(string $text, string $outPath): void
{
    $lines = [];
    foreach (preg_split("/\R/", $text) as $raw) {
        foreach (wrap_line($raw) as $wrapped) {
            $lines[] = $wrapped;
        }
    }

    $y = 750;
    $pageStreams = [];
    $current = [];

    foreach ($lines as $line) {
        if ($y < 56) {
            $pageStreams[] = implode("\n", $current);
            $current = [];
            $y = 750;
        }
        if ($line === "Contract Template") {
            $current[] = "0 0 0 rg";
            $current[] = sprintf("BT /F2 16 Tf 36 %.2f Td (%s) Tj ET", $y, pdf_escape($line));
            $y -= 22;
            continue;
        }
        if (preg_match('/^\d+\.\s+/', $line)) {
            $current[] = "0 0 0 rg";
            $current[] = sprintf("BT /F2 12 Tf 36 %.2f Td (%s) Tj ET", $y, pdf_escape($line));
            $y -= 18;
            continue;
        }
        if ($line === "") {
            $y -= 10;
            continue;
        }
        $current[] = "0 0 0 rg";
        $current[] = sprintf("BT /F1 11 Tf 36 %.2f Td (%s) Tj ET", $y, pdf_escape($line));
        $y -= 15;
    }
    $pageStreams[] = implode("\n", $current);

    $objects = [];
    $objects[] = "<< /Type /Catalog /Pages 2 0 R >>";
    $pageCount = count($pageStreams);
    $pageIds = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $pageIds[] = 3 + $i;
    }
    $kids = implode(" ", array_map(static fn($id) => "{$id} 0 R", $pageIds));
    $objects[] = "<< /Type /Pages /Kids [{$kids}] /Count {$pageCount} >>";

    $contentIds = [];
    for ($i = 0; $i < $pageCount; $i++) {
        $contentIds[] = 3 + $pageCount + $i;
    }
    $fontId = 3 + ($pageCount * 2);
    $fontBoldId = $fontId + 1;

    for ($i = 0; $i < $pageCount; $i++) {
        $cid = $contentIds[$i];
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$cid} 0 R /Resources << /Font << /F1 {$fontId} 0 R /F2 {$fontBoldId} 0 R >> >> >>";
    }
    foreach ($pageStreams as $stream) {
        $len = strlen($stream);
        $objects[] = "<< /Length {$len} >>\nstream\n{$stream}\nendstream";
    }
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>";
    $objects[] = "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $i => $body) {
        $offsets[] = strlen($pdf);
        $num = $i + 1;
        $pdf .= "{$num} 0 obj\n{$body}\nendobj\n";
    }
    $xrefPos = strlen($pdf);
    $count = count($objects) + 1;
    $pdf .= "xref\n0 {$count}\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i < $count; $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }
    $pdf .= "trailer << /Size {$count} /Root 1 0 R >>\nstartxref\n{$xrefPos}\n%%EOF";

    file_put_contents($outPath, $pdf);
}

foreach ($contracts as $c) {
    $text = contract_text($c);
    $txtPath = $outDir . "/" . $c["file"] . ".txt";
    $pdfPath = $outDir . "/" . $c["file"] . ".pdf";
    file_put_contents($txtPath, $text);
    write_pdf($text, $pdfPath);
    echo "Wrote {$c["file"]}.pdf\n";
}

echo "Done. Files are in samples/demo-contract-pack/\n";
