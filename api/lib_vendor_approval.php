<?php
declare(strict_types=1);

require_once __DIR__ . "/lib_mail.php";

const CONTRACK_VENDOR_APPROVAL_FIELDS = "id,name,company_name,email,contact_number,supplier_type,created_at,approval_status";

/**
 * Site address used in emailed links: CONTRACK_PUBLIC_URL, else the address Render provides.
 * The Host header is client-controlled, so it is only trusted for localhost.
 */
function contrack_public_base_url(): ?string
{
    foreach (["CONTRACK_PUBLIC_URL", "RENDER_EXTERNAL_URL"] as $key) {
        $configured = trim((string)env_value($key, ""));
        if ($configured !== "") {
            return rtrim($configured, "/");
        }
    }
    $host = strtolower((string)($_SERVER["HTTP_HOST"] ?? ""));
    if (preg_match('/^(localhost|127\.0\.0\.1)(:\d{1,5})?$/', $host) === 1) {
        return (env_flag("CONTRACK_HTTPS", false) ? "https" : "http") . "://" . $host;
    }
    return null;
}

/**
 * Addresses that receive vendor approval requests: every active CEO account, plus any extra
 * addresses listed in CONTRACK_CEO_APPROVAL_EMAIL (comma separated).
 * @return list<string>
 */
function contrack_ceo_approval_recipients(): array
{
    $emails = [];
    $result = supabase_request("GET", "users?role=eq.ceo&is_disabled=eq.false&select=email");
    if ($result["ok"] && is_array($result["data"])) {
        foreach ($result["data"] as $row) {
            $emails[] = strtolower(trim((string)($row["email"] ?? "")));
        }
    }
    foreach (explode(",", (string)env_value("CONTRACK_CEO_APPROVAL_EMAIL", "")) as $extra) {
        $emails[] = strtolower(trim($extra));
    }
    $emails = array_filter($emails, static fn(string $e): bool => filter_var($e, FILTER_VALIDATE_EMAIL) !== false);
    return array_values(array_unique($emails));
}

/** Email every CEO recipient about a new vendor signup. Returns how many emails were accepted. */
function contrack_send_vendor_approval_request(array $vendor, string $token): int
{
    $company = (string)($vendor["company_name"] ?? $vendor["name"] ?? "");
    $base = contrack_public_base_url();
    $lines = [
        "A new vendor signed up for ConTrack AI and is waiting for your approval.",
        "",
        "Company:        {$company}",
        "Email:          " . (string)($vendor["email"] ?? ""),
        "Contact number: " . (string)($vendor["contact_number"] ?? ""),
        "Supplier type:  " . (string)($vendor["supplier_type"] ?? ""),
        "",
        "The vendor cannot sign in until you approve the account.",
        "",
    ];
    if ($base !== null) {
        $tokenEnc = rawurlencode($token);
        $lines[] = "Approve: {$base}/api/vendor_approval.php?token={$tokenEnc}&decision=approve";
        $lines[] = "Reject:  {$base}/api/vendor_approval.php?token={$tokenEnc}&decision=reject";
    } else {
        error_log("vendor approval email sent without links: set CONTRACK_PUBLIC_URL");
        $lines[] = "Approval links are unavailable because CONTRACK_PUBLIC_URL is not set on the server.";
    }
    $body = implode("\r\n", $lines);
    $subject = "Vendor approval needed: {$company}";

    $sent = 0;
    $recipients = contrack_ceo_approval_recipients();
    $errors = [];
    foreach ($recipients as $to) {
        $error = contrack_send_mail($to, $subject, $body);
        if ($error === null) {
            $sent++;
        } else {
            $errors[$to] = $error;
            error_log("vendor approval email to {$to} failed: {$error}");
        }
    }
    audit_log_event(
        $sent > 0 ? "vendor_approval_email_sent" : "vendor_approval_email_failed",
        [
            "vendor_email" => (string)($vendor["email"] ?? ""),
            "recipients" => $recipients,
            "sent" => $sent,
            "has_links" => $base !== null,
            "errors" => $errors,
        ],
        isset($vendor["id"]) ? (int)$vendor["id"] : null
    );
    return $sent;
}

function contrack_find_vendor_by_approval_token(string $token): ?array
{
    $token = strtolower(trim($token));
    if (preg_match('/^[a-f0-9]{64}$/', $token) !== 1) {
        return null;
    }
    $hash = hash("sha256", $token);
    $result = supabase_request(
        "GET",
        "users?approval_token_hash=eq.{$hash}&select=" . CONTRACK_VENDOR_APPROVAL_FIELDS . "&limit=1"
    );
    $row = ($result["ok"] && is_array($result["data"][0] ?? null)) ? $result["data"][0] : null;
    return $row;
}

/**
 * Approve or reject a pending vendor. Only a row that is still pending is changed, so two CEOs
 * deciding at once cannot both win. The vendor is emailed the outcome.
 * @return array{ok:bool,error?:string,vendor?:array<string,mixed>}
 */
function contrack_decide_vendor(int $vendorId, string $decision, ?int $actorId, string $source): array
{
    if (!in_array($decision, ["approve", "reject"], true)) {
        return ["ok" => false, "error" => "Choose approve or reject."];
    }
    $status = $decision === "approve" ? "approved" : "rejected";
    $patch = [
        "approval_status" => $status,
        "approval_token_hash" => null,
        "approval_decided_at" => gmdate("c"),
        "approval_decided_by" => $actorId,
    ];
    $result = supabase_request(
        "PATCH",
        "users?id=eq.{$vendorId}&approval_status=eq.pending&select=" . CONTRACK_VENDOR_APPROVAL_FIELDS,
        $patch
    );
    if (!$result["ok"]) {
        return ["ok" => false, "error" => contrack_upstream_error($result, "Could not save the decision.")];
    }
    $vendor = is_array($result["data"][0] ?? null) ? $result["data"][0] : null;
    if ($vendor === null) {
        return ["ok" => false, "error" => "This vendor account was already approved or rejected."];
    }

    $company = (string)($vendor["company_name"] ?? $vendor["name"] ?? "");
    $email = (string)($vendor["email"] ?? "");
    audit_log_event(
        $status === "approved" ? "vendor_account_approved" : "vendor_account_rejected",
        ["vendor_id" => $vendorId, "email" => $email, "source" => $source],
        $actorId
    );

    $base = contrack_public_base_url();
    $body = $status === "approved"
        ? "Good news! Your ConTrack AI vendor account for {$company} was approved by the CEO.\r\n\r\n"
            . "You can now sign in" . ($base !== null ? " at {$base}/" : "") . " with the email and password you registered."
        : "Your ConTrack AI vendor account request for {$company} was not approved.\r\n\r\n"
            . "If you think this is a mistake, please contact the company directly.";
    $subject = $status === "approved" ? "Your ConTrack AI account is approved" : "Your ConTrack AI account request";
    if ($email !== "") {
        $mailError = contrack_send_mail($email, $subject, $body);
        if ($mailError !== null) {
            error_log("vendor decision email to {$email} failed: {$mailError}");
        }
    }

    return ["ok" => true, "vendor" => $vendor];
}
