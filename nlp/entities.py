"""Pull dates, money, parties, and term language from free-form clause text."""
from __future__ import annotations

import re
from datetime import datetime

MONTHS = (
    "January|February|March|April|May|June|July|August|"
    "September|October|November|December"
)
DATE_TOKEN = (
    rf"(?:(?:{MONTHS})\s+\d{{1,2}},?\s+\d{{4}}"
    rf"|\d{{4}}-\d{{2}}-\d{{2}}"
    rf"|\d{{1,2}}/\d{{1,2}}/\d{{2,4}}"
    rf"|\d{{1,2}}\s+(?:{MONTHS})\s+\d{{4}}"
    rf"|\d{{1,2}}-(?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[a-z]*-\d{{2,4}}"
    rf"|\d{{1,2}}\.\d{{1,2}}\.\d{{4}})"
)


def _clean(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def labeled(text: str, names: str) -> str | None:
    pattern = (
        rf"(?:{names})\s*[:\-]\s*(.+?)"
        rf"(?=\n\s*[-]?\s*(?:Contract Title|Contract Type|Effective Date|End Date|"
        rf"Client Name|Vendor Name|Vendor Address|Scope of Work|Contract Value|"
        rf"Currency|Payment Terms|Penalty Clause|Renewal Terms|Termination Clause|"
        rf"Client Authorized Signatory|Vendor Authorized Signatory|Signed Date)\s*:|\n\d+\.\s|$)"
    )
    match = re.search(pattern, text, flags=re.IGNORECASE | re.DOTALL)
    if not match:
        return None
    value = _clean(match.group(1))
    return value or None


def normalize_date(raw: str | None) -> str | None:
    if not raw:
        return None
    raw = raw.strip().replace(",", "")
    for fmt in (
        "%Y-%m-%d",
        "%m/%d/%Y",
        "%m/%d/%y",
        "%d/%m/%Y",
        "%B %d %Y",
        "%d %B %Y",
        "%d.%m.%Y",
        "%d-%b-%Y",
        "%d-%b-%y",
    ):
        try:
            return datetime.strptime(raw, fmt).strftime("%Y-%m-%d")
        except ValueError:
            continue
    try:
        return datetime.strptime(raw, "%B %d %Y").strftime("%Y-%m-%d")
    except ValueError:
        return None


def extract_dates(text: str) -> dict[str, str | None]:
    start = None
    end = None
    signed = None
    start_m = re.search(
        rf"(?:effective date|start date|commencement|begins(?:\s+on)?|term begins|take effect(?:\s+on)?|commencing(?:\s+on)?)\s*[:\-]?\s*({DATE_TOKEN})",
        text,
        re.I,
    )
    end_m = re.search(
        rf"(?:end date|expiry|expiration|termination date|expires|continue until|until|through|remain in force until)\s*[:\-]?\s*({DATE_TOKEN})",
        text,
        re.I,
    )
    signed_m = re.search(rf"(?:signed date|date of signing|signed on|executed this Agreement as of)\s*[:\-]?\s*({DATE_TOKEN})", text, re.I)
    range_m = re.search(
        rf"(?:from|between)\s+({DATE_TOKEN})\s+(?:to|through|and|–|-)\s+({DATE_TOKEN})",
        text,
        re.I,
    )
    if start_m:
        start = normalize_date(start_m.group(1))
    if end_m:
        end = normalize_date(end_m.group(1))
    if signed_m:
        signed = normalize_date(signed_m.group(1))
    if range_m:
        start = start or normalize_date(range_m.group(1))
        end = end or normalize_date(range_m.group(2))
    found = re.findall(DATE_TOKEN, text, flags=re.I)
    dates = [normalize_date(item) for item in found]
    dates = [d for d in dates if d]
    signed_only = bool(re.search(r"signed date", text, flags=re.I)) and not re.search(
        r"effective date|start date|end date", text, flags=re.I
    )
    if not start and dates and not signed_only:
        start = dates[0]
    if not end and len(dates) >= 2:
        end = dates[1] if dates[1] != start else (dates[-1] if dates[-1] != start else None)
    return {"start_date": start, "end_date": end, "signed_date": signed}


def extract_money(text: str) -> dict[str, str | float | None]:
    currency = None
    cur_m = re.search(r"\b(PHP|USD|EUR)\b|₱|\$", text, flags=re.I)
    if cur_m:
        token = cur_m.group(0).upper()
        currency = "PHP" if token in {"PHP", "₱"} else "USD" if token in {"USD", "$"} else "EUR" if token == "EUR" else "PHP"
    amount = None
    amt_m = re.search(
        r"(?:contract value|contract price|total(?:\s+contract)?\s+(?:value|fee|price)|consideration|amount|rent(?:al)?)\s*[:\-]?\s*(?:of\s+)?(?:the\s+sum\s+of\s+)?(?:PHP|USD|EUR|Php|₱|\$)?\s*([0-9][0-9,\s]*(?:\.[0-9]{1,2})?)",
        text,
        flags=re.I,
    )
    if not amt_m:
        amt_m = re.search(
            r"(?:PHP|USD|EUR|Php|₱)\s*([0-9][0-9,\s]*(?:\.[0-9]{1,2})?)",
            text,
            flags=re.I,
        )
    if not amt_m:
        amt_m = re.search(
            r"(?:Philippine Pesos|pesos)\s*(?:of\s*)?([0-9][0-9,]*(?:\.[0-9]{1,2})?)",
            text,
            flags=re.I,
        )
    if amt_m:
        amount = float(re.sub(r"[,\s]", "", amt_m.group(1)))
    if amount is None:
        found = re.findall(r"(?:PHP|USD|EUR|Php|₱)\s*([0-9][0-9,\s]*(?:\.[0-9]{1,2})?)", text, flags=re.I)
        parsed = []
        for item in found:
            try:
                parsed.append(float(re.sub(r"[,\s]", "", item)))
            except ValueError:
                continue
        if parsed:
            amount = max(parsed)
    return {"contract_value": amount, "currency": currency}


def extract_payment(text: str) -> str | None:
    labeled_pay = labeled(text, r"payment terms?")
    blob = labeled_pay or text
    lower = blob.lower()
    written = {"fifteen": 15, "thirty": 30, "forty-five": 45, "sixty": 60, "ninety": 90}
    for word, num in written.items():
        if re.search(rf"\bnet\s+{word}\b", lower):
            return f"{num} Days"
    net = re.search(r"\bnet\s*(\d{1,3})\b", lower) or re.search(r"\b(\d{1,3})\s*days?\b", lower)
    if net:
        return f"{int(net.group(1))} Days"
    if "milestone" in lower:
        return "Milestone-based"
    if "upfront" in lower or "advance" in lower or "due on receipt" in lower:
        return "Upfront"
    if re.search(r"\bmonthly\b", lower) or re.search(r"invoices?\s+are\s+monthly", lower):
        return "Monthly"
    return _clean(blob)[:180] if labeled_pay else None


def extract_renewal(text: str) -> str | None:
    labeled_val = labeled(text, r"renewal terms?")
    blob = labeled_val or text
    lower = blob.lower()
    if "no renewal" in lower or "shall not renew" in lower or "no automatic renewal" in lower:
        return "No renewal"
    if "manual" in lower:
        return "Manual renewal only"
    if "auto" in lower and re.search(r"\b6\s*month|\bsix[ -]month", lower):
        return "Auto-renew (6 months)"
    if "auto" in lower or "automatically renew" in lower:
        return "Auto-renew (12 months)"
    return _clean(blob)[:240] if labeled_val or "renew" in lower else None


def extract_type(text: str) -> str | None:
    labeled_val = labeled(text, r"contract type|agreement type|type of contract")
    blob = (labeled_val or text).lower()
    if re.search(r"supply agreement", blob):
        return "Supply Agreement"
    if re.search(r"lease agreement|\blease of\b", blob):
        return "Lease Agreement"
    if re.search(r"service agreement", blob):
        return "Service Agreement"
    if "consult" in blob:
        return "Consulting Contract"
    if "maintain" in blob:
        return "Maintenance Contract"
    if re.search(r"\blease\b", blob) and not re.search(r"please", blob):
        return "Lease Agreement"
    if re.search(r"\b(services?|janitorial|cleaning|security|catering|crew|consult)\b|shall provide|shall furnish", blob):
        return "Service Agreement"
    if re.search(r"\bsupply\b", blob) and re.search(r"\bgoods|materials|equipment\b", blob):
        return "Supply Agreement"
    return labeled_val


def extract_parties(text: str) -> dict[str, str | None]:
    client = labeled(text, r"client name|lessee(?:\s+name)?|buyer(?:\s+name)?|party a")
    vendor = labeled(text, r"vendor name|supplier name|contractor name|lessor(?:\s+name)?|seller(?:\s+name)?|party b")
    address = labeled(text, r"vendor address|lessor address|supplier address|principal place of business")
    if not client:
        m = re.search(r"(?:CLIENT|LESSEE|BUYER|PARTY A)\s*[:\-]\s*([^,\n]+)", text, flags=re.I)
        if m:
            client = _clean(m.group(1))
    if not vendor:
        m = re.search(r"(?:VENDOR|SUPPLIER|CONTRACTOR|LESSOR|SELLER|PARTY B)\s*[:\-]\s*([^,\n]+)", text, flags=re.I)
        if m:
            vendor = _clean(m.group(1))
    if not client:
        m = re.search(
            r"between\s+(.+?)\s+\((?:the\s+)?[\"']?(?:Client|Lessee|Buyer|Company|Procuring Entity)[\"']?\)",
            text,
            flags=re.I,
        )
        if m:
            client = _clean(m.group(1))
    if not vendor:
        m = re.search(
            r"and\s+(.+?)(?:,\s*with offices at|\s+\((?:the\s+)?[\"']?(?:Vendor|Lessor|Seller|Contractor|Supplier)[\"']?\))",
            text,
            flags=re.I,
        )
        if m:
            vendor = _clean(m.group(1))
    pair = re.search(
        r"between\s+(.+?),\s*hereinafter referred to as (?:the\s+)?(?:CLIENT|LESSEE).{0,80}?and\s+(.+?),\s*hereinafter referred to as (?:the\s+)?(?:VENDOR|LESSOR|CONTRACTOR)",
        text,
        flags=re.I | re.S,
    )
    if pair:
        client = client or _clean(re.sub(r"\s+", " ", pair.group(1)))
        vendor = vendor or _clean(re.sub(r"\s+", " ", pair.group(2)))
    if not address:
        m = re.search(
            r"(?:with offices at|principal offices? at|located at|address[:\-]\s*)([^.\n]+)",
            text,
            flags=re.I,
        )
        if m:
            address = _clean(m.group(1))
            address = re.sub(r"\s*\([^)]*\)\s*$", "", address).strip(" ,")
    client_sign = labeled(text, r"client authorized signatory|signatory\s*\(?client\)?")
    vendor_sign = labeled(text, r"vendor authorized signatory|signatory\s*\(?vendor\)?")
    if not client_sign:
        m = re.search(r"(?:for the client|client:)\s*([A-Z][a-zA-Z .]+,\s*[^.\n]+)", text)
        if m:
            client_sign = _clean(m.group(1))
    if not vendor_sign:
        m = re.search(r"(?:for the vendor|vendor:)\s*([A-Z][a-zA-Z .]+,\s*[^.\n]+)", text)
        if m:
            vendor_sign = _clean(m.group(1))
    return {
        "client_name": client,
        "vendor_name": vendor,
        "vendor_address": address,
        "client_signatory": client_sign,
        "vendor_signatory": vendor_sign,
    }


def strip_label(text: str) -> str:
    return _clean(re.sub(r"^[\-\s]*(?:[A-Za-z][A-Za-z ]{2,40})\s*[:\-]\s*", "", text or "", count=1))
