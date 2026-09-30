"""PDF + text clause extraction using the trained sklearn classifier."""
from __future__ import annotations

import re
import sys
import unicodedata
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from entities import (  # noqa: E402
    extract_dates,
    extract_money,
    extract_parties,
    extract_payment,
    extract_renewal,
    extract_type,
    labeled,
    strip_label,
)
from train import load_or_train  # noqa: E402

_FANCY_CHARS = str.maketrans(
    {
        "\u2018": "'",
        "\u2019": "'",
        "\u201a": "'",
        "\u201b": "'",
        "\u201c": '"',
        "\u201d": '"',
        "\u201e": '"',
        "\u2013": "-",
        "\u2014": "-",
        "\u2212": "-",
        "\u00a0": " ",
        "\u00ad": "",
        "\u2026": "...",
        "\u02c6": "n",
    }
)


def sanitize_extracted_text(text: str) -> str:
    """Normalize PDF/Windows text so PHP json_decode on Windows never sees raw Unicode."""
    text = unicodedata.normalize("NFKC", text or "")
    text = text.replace("\u02c6\u2019", "e")
    text = text.translate(_FANCY_CHARS)
    decomposed = unicodedata.normalize("NFKD", text)
    return decomposed.encode("ascii", "ignore").decode("ascii")


FIELD_FROM_LABEL = {
    "title": "contract_title",
    "type": "contract_type",
    "scope": "scope",
    "penalty": "penalty_clause",
    "renewal": "renewal_terms",
    "termination": "termination_clause",
}

_MODEL = None


def _model():
    global _MODEL
    if _MODEL is None:
        _MODEL = load_or_train()
    return _MODEL


def pdf_to_text(pdf_path: str) -> str:
    from pypdf import PdfReader

    reader = PdfReader(pdf_path)
    pages = []
    for page in reader.pages:
        pages.append(page.extract_text() or "")
    return sanitize_extracted_text(unwrap_pdf_text("\n".join(pages)))


def unwrap_pdf_text(text: str) -> str:
    lines = text.splitlines()
    out: list[str] = []
    buf = ""
    for raw in lines:
        line = raw.strip()
        if not line:
            if buf:
                out.append(buf)
                buf = ""
            continue
        starts_field = bool(
            re.match(
                r"^(?:- |\d+\.\s)?(?:Contract Title|Contract Type|Effective Date|End Date|"
                r"Client Name|Vendor Name|Vendor Address|Scope of Work|Contract Value|"
                r"Currency|Payment Terms|Penalty Clause|Renewal Terms|Termination Clause|"
                r"Client Authorized Signatory|Vendor Authorized Signatory|Signed Date)\s*:",
                line,
                flags=re.I,
            )
        ) or bool(re.match(r"^\d+\.\s+", line)) or line.lower() == "contract template"
        if buf and (starts_field or (line.startswith("- ") and ":" in line)):
            out.append(buf)
            buf = line
        elif not buf:
            buf = line
        else:
            buf += " " + line
    if buf:
        out.append(buf)
    return "\n".join(out)


def segment(text: str) -> list[str]:
    if not text.strip():
        return []
    parts = re.split(
        r"\n\s*\n|(?=^\d+\.\s)|(?=^[A-Z][A-Z /&]{8,}:)|(?=^#{1,3}\s)"
        r"|(?=WHEREAS)|(?=NOW,? THEREFORE)|(?=IN WITNESS)|(?=^Section\s+\d)|(?=^Article\s+\d)|(?=^ARTICLE\s)",
        text,
        flags=re.M,
    )
    chunks: list[str] = []
    for part in parts:
        block = part.strip()
        if len(block) < 12:
            continue
        chunks.append(block)
        for line in block.splitlines():
            line = line.strip()
            if ":" in line and 12 < len(line) < 500:
                chunks.append(line)
    seen: set[str] = set()
    unique: list[str] = []
    for item in chunks:
        key = re.sub(r"\s+", " ", item).lower()
        if key in seen:
            continue
        seen.add(key)
        unique.append(item)
    return unique


def classify_chunks(chunks: list[str]) -> list[tuple[str, str, float]]:
    if not chunks:
        return []
    model = _model()
    labels = model.predict(chunks)
    proba = model.predict_proba(chunks)
    classes = list(model.classes_)
    scored = []
    for chunk, label, row in zip(chunks, labels, proba):
        conf = float(row[classes.index(label)])
        scored.append((chunk, str(label), conf))
    return scored


def _best_chunk(scored: list[tuple[str, str, float]], label: str, min_conf: float = 0.16) -> str | None:
    matches = [(chunk, conf) for chunk, lab, conf in scored if lab == label and conf >= min_conf]
    if not matches:
        return None
    matches.sort(key=lambda item: (item[1], len(item[0])), reverse=True)
    return matches[0][0]


def empty_result() -> dict:
    return {
        "contract_title": None,
        "contract_type": None,
        "contract_value": None,
        "start_date": None,
        "end_date": None,
        "payment_terms": None,
        "scope": None,
        "renewal_terms": None,
        "penalty_clause": None,
        "financial_obligations": None,
        "classification": None,
        "client_name": None,
        "vendor_name": None,
        "vendor_address": None,
        "currency": None,
        "termination_clause": None,
        "client_signatory": None,
        "vendor_signatory": None,
        "signed_date": None,
        "contract_text": "",
        "engine": "python-ml",
        "clauses": [],
    }


def assemble(text: str, scored: list[tuple[str, str, float]]) -> dict:
    result = empty_result()
    result["contract_text"] = text
    result["clauses"] = [
        {"label": lab, "confidence": round(conf, 3), "text": chunk[:400]}
        for chunk, lab, conf in scored
        if lab != "other" and conf >= 0.22
    ]

    parties_blob = _best_chunk(scored, "parties") or text
    dates_blob = _best_chunk(scored, "dates") or text
    value_blob = _best_chunk(scored, "value") or text
    pay_blob = _best_chunk(scored, "payment") or text
    sign_blob = _best_chunk(scored, "signatures") or text

    for label, field in FIELD_FROM_LABEL.items():
        chunk = _best_chunk(scored, label)
        if chunk:
            result[field] = strip_label(chunk)

    title_line = labeled(text, r"contract title|agreement title|title of agreement")
    if title_line:
        result["contract_title"] = title_line
    elif result["contract_title"] and result["contract_title"].lower().startswith("contract template"):
        result["contract_title"] = None
    if result["contract_title"] and re.fullmatch(r"contract template", result["contract_title"], flags=re.I):
        result["contract_title"] = None
    if not result["contract_title"]:
        for line in text.splitlines():
            line = line.strip(" -")
            if re.search(r"renew|penalt|terminat|invoice|liquidat|confidential|governing|take effect|remain in force|commenc", line, flags=re.I):
                continue
            if re.search(r"\b(agreement|contract|memorandum)\b", line, flags=re.I) and not re.match(
                r"^(contract template|contract type)\b", line, flags=re.I
            ):
                if 12 < len(line) < 160:
                    result["contract_title"] = re.sub(r"^(?:contract title|title)\s*[:\-]\s*", "", line, flags=re.I)
                    break

    result["contract_type"] = extract_type(_best_chunk(scored, "type") or result.get("contract_title") or text) or result["contract_type"]

    parties = extract_parties(parties_blob)
    parties_full = extract_parties(text)
    for key in parties:
        result[key] = parties.get(key) or parties_full.get(key)

    if not result["contract_title"]:
        bits = [x for x in (result.get("contract_type"), result.get("vendor_name")) if x]
        if bits:
            result["contract_title"] = " — ".join(bits)

    dates_full = extract_dates(text)
    dates = extract_dates(dates_blob)
    result["start_date"] = dates_full.get("start_date") or dates.get("start_date")
    result["end_date"] = dates_full.get("end_date") or dates.get("end_date")
    result["signed_date"] = dates_full.get("signed_date") or dates.get("signed_date")

    money = extract_money(value_blob)
    money_full = extract_money(text)
    result["contract_value"] = money.get("contract_value") or money_full.get("contract_value")
    result["currency"] = money.get("currency") or money_full.get("currency") or "PHP"

    result["payment_terms"] = extract_payment(pay_blob) or extract_payment(text) or result["payment_terms"]
    result["renewal_terms"] = extract_renewal(_best_chunk(scored, "renewal") or "") or extract_renewal(text) or result["renewal_terms"]

    term_labeled = labeled(text, r"termination clause")
    if term_labeled:
        result["termination_clause"] = term_labeled
    else:
        term_chunk = _best_chunk(scored, "termination")
        if term_chunk:
            result["termination_clause"] = strip_label(term_chunk)

    pen_labeled = labeled(text, r"penalty(?:\s+clause)?|liquidated damages")
    if pen_labeled:
        result["penalty_clause"] = pen_labeled
    elif not result["penalty_clause"]:
        pen_chunk = _best_chunk(scored, "penalty")
        if pen_chunk:
            result["penalty_clause"] = strip_label(pen_chunk)
        else:
            pen_m = re.search(
                r"([^.]*\b(?:liquidated damages|late fee|service credit|surcharge of)[^.]*\.)",
                text,
                flags=re.I,
            )
            if pen_m:
                result["penalty_clause"] = strip_label(pen_m.group(1))

    scope_labeled = labeled(text, r"scope of work|statement of work|sow")
    if scope_labeled:
        result["scope"] = scope_labeled
    elif not result["scope"]:
        scope_chunk = _best_chunk(scored, "scope")
        if scope_chunk:
            result["scope"] = strip_label(scope_chunk)
        else:
            scope_m = re.search(
                r"([^.]*\b(?:shall (?:furnish|provide|deliver|lease)|duties include|services cover)[^.]*\.)",
                text,
                flags=re.I,
            )
            if scope_m:
                result["scope"] = strip_label(scope_m.group(1))

    sign_parts = extract_parties(sign_blob)
    if sign_parts.get("client_signatory"):
        result["client_signatory"] = sign_parts["client_signatory"]
    if sign_parts.get("vendor_signatory"):
        result["vendor_signatory"] = sign_parts["vendor_signatory"]

    bits = []
    if result["contract_value"]:
        bits.append(f"Contract value {result['currency']} {result['contract_value']:,.2f}")
    if result["payment_terms"]:
        bits.append(f"Payment: {result['payment_terms']}")
    if result["penalty_clause"]:
        bits.append(f"Penalties: {result['penalty_clause']}")
    result["financial_obligations"] = "; ".join(bits) if bits else None
    result["classification"] = result["contract_type"]
    return result


def extract_from_text(text: str) -> dict:
    text = sanitize_extracted_text(text or "")
    scored = classify_chunks(segment(text))
    return assemble(text, scored)


def extract_from_pdf(pdf_path: str) -> dict:
    text = pdf_to_text(pdf_path)
    result = extract_from_text(text)
    result["source_pdf"] = pdf_path
    return result
