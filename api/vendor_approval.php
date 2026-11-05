<?php
declare(strict_types=1);

/**
 * Landing page for the Approve / Reject links in the CEO's email. Opening the link only shows the
 * request; the decision is saved by the confirm button (POST), because mail scanners open links on
 * their own and must not approve anyone.
 */
const CONTRACK_SKIP_JSON_HEADER = true;
require_once __DIR__ . "/config.php";
require_once __DIR__ . "/lib_vendor_approval.php";

header("Content-Type: text/html; charset=utf-8");
header("Cache-Control: no-store");
// "no-referrer" would make the confirm POST send Origin: null, which contrack_enforce_same_origin() rejects.
header("Referrer-Policy: same-origin");
header("X-Robots-Tag: noindex");

$h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, "UTF-8");

$render = static function (string $title, string $bodyHtml) use ($h): never {
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
        . '<title>' . $h($title) . ' — ConTrack AI</title>'
        . '<style>'
        . 'body{margin:0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f3f4f6;color:#111827;}'
        . '.card{max-width:520px;margin:64px auto;background:#fff;border-radius:14px;border:1px solid #e5e7eb;padding:32px;box-shadow:0 10px 30px rgba(0,0,0,.06);}'
        . 'h1{font-size:20px;margin:0 0 6px;} p{color:#4b5563;line-height:1.5;font-size:14px;}'
        . '.brand{font-weight:800;font-size:13px;letter-spacing:.08em;color:#6b7280;margin-bottom:18px;} .brand span{color:#2563eb;}'
        . 'dl{display:grid;grid-template-columns:140px 1fr;gap:8px 12px;font-size:14px;margin:18px 0;} dt{color:#6b7280;} dd{margin:0;font-weight:600;}'
        . '.actions{display:flex;gap:10px;margin-top:22px;} button{flex:1;border:0;border-radius:10px;padding:12px 16px;font-weight:700;font-size:14px;cursor:pointer;color:#fff;}'
        . '.approve{background:#16a34a;} .reject{background:#dc2626;} a{color:#2563eb;}'
        . '</style></head><body><div class="card"><div class="brand">CON<span>TRACK</span> AI</div>'
        . $bodyHtml
        . '</div></body></html>';
    exit;
};

$method = strtoupper((string)($_SERVER["REQUEST_METHOD"] ?? "GET"));
$token = (string)($method === "POST" ? ($_POST["token"] ?? "") : ($_GET["token"] ?? ""));
$decision = strtolower((string)($method === "POST" ? ($_POST["decision"] ?? "") : ($_GET["decision"] ?? "")));
if (!in_array($decision, ["approve", "reject"], true)) {
    $decision = "approve";
}

$vendor = contrack_find_vendor_by_approval_token($token);
if ($vendor === null || ($vendor["approval_status"] ?? "") !== "pending") {
    http_response_code(404);
    $render(
        "Link no longer valid",
        '<h1>This link is no longer valid</h1>'
        . '<p>The vendor account was already approved or rejected, or the link is incorrect.</p>'
    );
}

$company = (string)($vendor["company_name"] ?? $vendor["name"] ?? "");

if ($method === "POST") {
    $result = contrack_decide_vendor((int)$vendor["id"], $decision, null, "email_link");
    if (!$result["ok"]) {
        http_response_code(409);
        $render("Could not save", '<h1>Could not save the decision</h1><p>' . $h($result["error"] ?? "") . '</p>');
    }
    $approved = $decision === "approve";
    $render(
        $approved ? "Vendor approved" : "Vendor rejected",
        '<h1>' . ($approved ? "Vendor approved" : "Vendor rejected") . '</h1>'
        . '<p><strong>' . $h($company) . '</strong> '
        . ($approved
            ? "can now sign in to ConTrack AI. They have been notified by email."
            : "will not be able to sign in. They have been notified by email.")
        . '</p>'
    );
}

$verb = $decision === "approve" ? "Approve" : "Reject";
$created = strtotime((string)($vendor["created_at"] ?? ""));
$render(
    "{$verb} vendor",
    '<h1>' . $h($verb) . ' this vendor account?</h1>'
    . '<p>This vendor signed up and cannot sign in until you approve the account.</p>'
    . '<dl>'
    . '<dt>Company</dt><dd>' . $h($company) . '</dd>'
    . '<dt>Email</dt><dd>' . $h($vendor["email"] ?? "") . '</dd>'
    . '<dt>Contact number</dt><dd>' . $h($vendor["contact_number"] ?? "—") . '</dd>'
    . '<dt>Supplier type</dt><dd>' . $h($vendor["supplier_type"] ?? "—") . '</dd>'
    . '<dt>Signed up</dt><dd>' . $h($created !== false ? gmdate("M j, Y g:i A", $created) . " UTC" : "—") . '</dd>'
    . '</dl>'
    . '<form method="post" class="actions">'
    . '<input type="hidden" name="token" value="' . $h($token) . '">'
    . '<button type="submit" name="decision" value="approve" class="approve">✓ Approve</button>'
    . '<button type="submit" name="decision" value="reject" class="reject">✗ Reject</button>'
    . '</form>'
);
