<?php
declare(strict_types=1);

/** Shared signup/login field rules (must match CreatePassword.html, signup.js, and sql/schema_phase1.sql). */

const CONTRACK_NAME_REGEX = "/^[A-Za-z0-9][A-Za-z0-9&.,'\\/()\\- ]{0,118}[A-Za-z0-9.)]$/";
const CONTRACK_PH_MOBILE_REGEX = "/^(09[0-9]{9}|\\+639[0-9]{9}|639[0-9]{9})$/";
const CONTRACK_SUPPLIER_TYPES = ["Supply", "Lease", "Service", "Other"];

function contrack_normalize_contact(string $raw): string
{
    return preg_replace("/\s+/", "", trim($raw)) ?? "";
}

function contrack_name_error(string $name, string $label = "Name"): ?string
{
    $name = trim($name);
    $len = strlen($name);
    if ($name === "") {
        return "{$label} is required.";
    }
    if ($len < 2 || $len > 120) {
        return "{$label} must be 2–120 characters.";
    }
    if (!preg_match(CONTRACK_NAME_REGEX, $name)) {
        return "{$label} may contain letters, numbers, spaces, and . , & ' - / ( ).";
    }
    return null;
}

function contrack_company_error(string $name, bool $required): ?string
{
    $name = trim($name);
    if ($name === "") {
        return $required ? "Company name is required." : null;
    }
    $len = function_exists("mb_strlen") ? mb_strlen($name) : strlen($name);
    if ($len < 2 || $len > 120) {
        return "Company name must be 2–120 characters.";
    }
    if (preg_match("/[\\x00-\\x1F\\x7F]/", $name)) {
        return "Company name cannot include line breaks.";
    }
    return null;
}

function contrack_email_error(string $email): ?string
{
    $email = strtolower(trim($email));
    if ($email === "") {
        return "Email is required.";
    }
    if (strlen($email) > 254) {
        return "Email must be 254 characters or fewer.";
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strpos($email, "@") === false) {
        return "Enter a valid email address.";
    }
    return null;
}

function contrack_contact_error(string $raw, bool $required): ?string
{
    $contact = contrack_normalize_contact($raw);
    if ($contact === "") {
        return $required ? "Contact number is required." : null;
    }
    if (preg_match("/[A-Za-z]/", $contact) || !preg_match("/^\\+?[0-9]+$/", $contact)) {
        return "Contact number may contain digits only, with an optional leading +.";
    }
    if (preg_match("/^\\+?0+$/", $contact)) {
        return "Contact number cannot be all zeros.";
    }
    if (!preg_match(CONTRACK_PH_MOBILE_REGEX, $contact)) {
        return "Enter a Philippine mobile number (09XXXXXXXXX, 639XXXXXXXXX, or +639XXXXXXXXX).";
    }
    return null;
}

function contrack_supplier_error(string $type, bool $required): ?string
{
    $type = trim($type);
    if ($type === "" || strcasecmp($type, "Select supplier type") === 0) {
        return $required ? "Select a supplier type." : null;
    }
    if (!in_array($type, CONTRACK_SUPPLIER_TYPES, true)) {
        return "Supplier type must be Supply, Lease, Service, or Other.";
    }
    return null;
}

function contrack_password_error(string $password): ?string
{
    $len = strlen($password);
    if ($password === "") {
        return "Password is required.";
    }
    if ($len < 8 || $len > 64) {
        return "Password must be 8–64 characters.";
    }
    if (!preg_match("/[A-Za-z]/", $password) || !preg_match("/[0-9]/", $password)) {
        return "Password must include at least one letter and one number.";
    }
    return null;
}
