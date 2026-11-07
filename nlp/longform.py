"""Structure-aware extraction for long-form contracts.

Real contracts bury the handful of facts ConTrack needs (parties, term, price,
payment, penalty, renewal, termination, signatories) inside pages of WHEREAS
recitals, definitions, and boilerplate. This module:

1. splits the document into cover / table of contents / preamble / recitals /
   articles / signature block / acknowledgment / annexes,
2. discards the noise (recitals, definitions, notarial pages, annexes),
3. routes each remaining paragraph to a system field by combining the article
   heading with the trained clause classifier's probability, and
4. parses only the selected paragraphs into the system's field values.
"""
from __future__ import annotations

import re
from dataclasses import dataclass, field

from entities import DATE_TOKEN, extract_type, normalize_date

ROMAN_OR_NUM = r"(?:[IVXLC]{1,7}|\d{1,2})"
ARTICLE_RE = re.compile(rf"^\s*(?:ARTICLE|Article|SECTION|Section)\s+{ROMAN_OR_NUM}\b[\s.:\-]*(.*)$")
NUMBERED_HEADING_RE = re.compile(r"^\s*(\d{1,2})\.\s+([A-Z][A-Z0-9 ,'&/\-()]{3,80})$")
CAPS_LINE_RE = re.compile(r"^[A-Z][A-Z0-9 ,.'&/\-()]{2,90}:?$")
CLAUSE_NUM_RE = re.compile(r"^\s*(?:\d{1,2}(?:\.\d{1,2}){1,3}\.?|\(\w{1,4}\))\s+")
ANNEX_RE = re.compile(r"^\s*(?:ANNEX|EXHIBIT|SCHEDULE|APPENDIX)\s+[A-Z0-9]{1,3}\b")
ACK_RE = re.compile(r"^\s*(?:ACKNOWLEDG(?:E)?MENT|JURAT|NOTARIAL (?:CERTIFICATE|ACKNOWLEDG(?:E)?MENT))\s*$")

CLIENT_ROLES = ("client", "lessee", "buyer", "purchaser", "customer", "company", "owner", "procuring entity", "principal")
VENDOR_ROLES = ("vendor", "lessor", "supplier", "seller", "contractor", "service provider", "provider", "consultant", "agency", "caterer")

HEADING_FIELDS: dict[str, str] = {
    "scope": r"\bSCOPE\b|OBJECT OF|LEASED PREMISES|STATEMENT OF WORK|DESCRIPTION OF (?:THE )?(?:SERVICES|WORKS?|GOODS)|SERVICES TO BE|SUBJECT MATTER|DELIVERABLES|^(?:THE\s+)?(?:SERVICES|WORKS?|GOODS|PRODUCTS)$",
    "dates": r"^(?:THE\s+)?(?:TERM|DURATION|EFFECTIVITY|COMMENCEMENT|CONTRACT PERIOD|LEASE PERIOD|PERIOD OF)\b(?!INATION)",
    "renewal": r"RENEWAL|EXTENSION OF (?:THE )?TERM",
    "value": r"CONTRACT PRICE|CONSIDERATION|\bRENT(?:AL)?\b|\bFEES?\b|COMPENSATION|\bPRICE\b|CONTRACT (?:SUM|AMOUNT|VALUE)",
    "payment": r"PAYMENT|BILLING|INVOIC",
    "penalty": r"PENALT|LIQUIDATED DAMAGES|SERVICE CREDITS?",
    "termination": r"^(?:PRE-?|EARLY )?TERMINATION\b",
}
HEADING_EXCLUDE: dict[str, str] = {
    "value": r"SECURITY|DEPOSIT|BOND|INSURANCE|ADVANCE",
    "dates": r"TERMINATION|RENEWAL",
}
MODEL_LABEL = {"scope": "scope", "dates": "dates", "renewal": "renewal", "value": "value",
               "payment": "payment", "penalty": "penalty", "termination": "termination"}

MONEY_RE = re.compile(
    r"(PHP|Php|PhP|₱|USD|US\$|EUR|€|\$)\s?([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{1,2})?|[0-9]{4,}(?:\.[0-9]{1,2})?)"
)
COMPANY_SUFFIX = r"(?:Inc|Corp|Corporation|Co|Company|Ltd|LLC|Cooperative|Enterprises?|Group)\.?"


@dataclass
class Section:
    kind: str
    heading: str = ""
    paragraphs: list[str] = field(default_factory=list)


def _clean(text: str) -> str:
    return re.sub(r"\s+", " ", text or "").strip()


def _strip_clause_number(text: str) -> str:
    return CLAUSE_NUM_RE.sub("", text, count=1).strip()


def _truncate_sentences(text: str, limit: int = 700) -> str:
    text = _clean(text)
    if len(text) <= limit:
        return text
    cut = text[:limit]
    end = max(cut.rfind(". "), cut.rfind("; "))
    return (cut[: end + 1] if end > limit * 0.4 else cut.rstrip() + "...").strip()


def smart_case(text: str) -> str:
    """'CARLO M. REYES' -> 'Carlo M. Reyes'; leaves mixed-case text alone."""
    if not text or not text.isupper():
        return text
    words = []
    for word in text.split():
        words.append("-".join(part[:1].upper() + part[1:].lower() for part in word.split("-")))
    return " ".join(words)


# --------------------------------------------------------------------------
# Segmentation
# --------------------------------------------------------------------------

def _is_heading_text(line: str) -> bool:
    return bool(CAPS_LINE_RE.match(line)) and len(line) <= 90 and not line.rstrip().endswith((".", ";"))


def split_sections(paragraphs: list[str]) -> list[Section]:
    sections: list[Section] = [Section("cover")]
    mode = "cover"
    i = 0
    n = len(paragraphs)
    while i < n:
        para = paragraphs[i].strip()
        i += 1
        if not para:
            continue
        upper = para.upper()

        if re.match(r"^TABLE OF CONTENTS\b", upper):
            mode = "toc"
            sections.append(Section("toc", "TABLE OF CONTENTS"))
            continue
        if mode == "toc":
            if len(para) > 120 or upper.startswith("KNOW ALL") or re.match(r"^THIS\s", upper):
                mode = "preamble"
                sections.append(Section("preamble"))
            else:
                sections[-1].paragraphs.append(para)
                continue

        if upper.startswith("KNOW ALL") and mode in {"cover", "preamble"}:
            mode = "preamble"
            sections.append(Section("preamble"))
            sections[-1].paragraphs.append(para)
            continue
        if re.match(r"^IN WITNESS WHEREOF", upper):
            mode = "signature"
            sections.append(Section("signature", "IN WITNESS WHEREOF", [para]))
            continue
        if ACK_RE.match(upper) and mode in {"signature", "articles", "annex"}:
            mode = "acknowledgment"
            sections.append(Section("acknowledgment", para))
            continue
        if ANNEX_RE.match(upper) and mode in {"signature", "acknowledgment", "annex"}:
            mode = "annex"
            sections.append(Section("annex", para))
            continue
        if mode in {"signature", "acknowledgment", "annex"}:
            sections[-1].paragraphs.append(para)
            continue

        if re.match(r"^(?:W\s*I\s*T\s*N\s*E\s*S\s*S\s*E\s*T\s*H|RECITALS|BACKGROUND|PREMISES)\s*:?\s*$", upper):
            mode = "recitals"
            sections.append(Section("recitals", para))
            continue
        if re.match(r"^WHEREAS\b", upper):
            if mode != "recitals":
                mode = "recitals"
                sections.append(Section("recitals", "WHEREAS"))
            sections[-1].paragraphs.append(para)
            continue
        if re.match(r"^NOW,?\s+THEREFORE\b", upper):
            sections.append(Section("recitals", "NOW, THEREFORE", [para]))
            mode = "articles"
            continue

        art = ARTICLE_RE.match(para)
        num = NUMBERED_HEADING_RE.match(para)
        if art or num:
            heading = _clean(art.group(1) if art else num.group(2)).strip(" -:.")
            if not heading and i < n and _is_heading_text(paragraphs[i].strip()):
                heading = paragraphs[i].strip().strip(" -:.")
                i += 1
            if heading and len(heading) <= 90 and not re.search(r"[.;]\s|\b(?:shall|will|may)\b", heading):
                mode = "articles"
                sections.append(Section("article", heading.upper()))
                continue
        if mode == "articles" and _is_heading_text(para) and len(para) <= 70 and i < n and len(paragraphs[i]) > 80:
            sections.append(Section("article", para.strip(" -:.").upper()))
            continue

        if mode == "cover" and (re.match(r"^THIS\s", upper) or "made and entered" in para.lower()):
            mode = "preamble"
            sections.append(Section("preamble"))
        sections[-1].paragraphs.append(para)
    return [s for s in sections if s.paragraphs or s.kind == "article"]


TEMPLATE_LABEL_RE = re.compile(
    r"^\W*(?:Contract Title|Contract Type|Effective Date|End Date|Client Name|Vendor Name|Contract Value|"
    r"Payment Terms|Renewal Terms|Termination Clause|Signed Date)\s*:",
    re.I | re.M,
)


def is_long_form(sections: list[Section], text: str) -> bool:
    """Labeled ConTrack templates are handled by the template path; this is for narrative contracts."""
    if len(TEMPLATE_LABEL_RE.findall(text)) >= 4 or len(text) < 3000:
        return False
    articles = [s for s in sections if s.kind == "article" and any(len(p) > 60 for p in s.paragraphs)]
    whereas = sum(len(s.paragraphs) for s in sections if s.kind == "recitals")
    return len(articles) >= 4 or whereas >= 3


# --------------------------------------------------------------------------
# Field routing (heading prior + classifier probability)
# --------------------------------------------------------------------------

def _heading_match(field_name: str, heading: str) -> bool:
    if not heading:
        return False
    exclude = HEADING_EXCLUDE.get(field_name)
    if exclude and re.search(exclude, heading):
        return False
    return bool(re.search(HEADING_FIELDS[field_name], heading))


def _route(sections: list[Section], model) -> tuple[dict[str, tuple[str, str, float]], int]:
    candidates: list[tuple[Section, int, str]] = []
    for section in sections:
        if section.kind != "article":
            continue
        if re.search(r"DEFINITION|INTERPRETATION", section.heading):
            continue
        for idx, para in enumerate(section.paragraphs):
            if len(para) >= 40:
                candidates.append((section, idx, para))
    if not candidates:
        return {}, 0

    texts = [_strip_clause_number(p) for _, _, p in candidates]
    proba = model.predict_proba(texts)
    classes = list(model.classes_)

    best: dict[str, tuple[float, str, str, float]] = {}
    for (section, idx, _), text, row in zip(candidates, texts, proba):
        for field_name, label in MODEL_LABEL.items():
            p = float(row[classes.index(label)]) if label in classes else 0.0
            if _heading_match(field_name, section.heading):
                score = 1.0 + 0.5 * p - 0.3 * idx
            elif p >= 0.5 and classes[int(row.argmax())] == label:
                score = 0.8 * p
            else:
                continue
            if score > best.get(field_name, (-1.0,))[0]:
                best[field_name] = (score, text, section.heading, p)
    routed = {k: (text, heading, round(p, 3)) for k, (_, text, heading, p) in best.items()}
    return routed, len(candidates)


# --------------------------------------------------------------------------
# Parsers for the selected text
# --------------------------------------------------------------------------

DATE_RE = re.compile(DATE_TOKEN, re.I)


def ordered_dates(text: str) -> list[str]:
    out = []
    for match in DATE_RE.finditer(text or ""):
        iso = normalize_date(match.group(0))
        if iso:
            out.append(iso)
    return out


def parse_money(text: str) -> tuple[float | None, str | None]:
    best: tuple[float, str] | None = None
    for cur, amount in MONEY_RE.findall(text or ""):
        try:
            value = float(amount.replace(",", ""))
        except ValueError:
            continue
        currency = "USD" if cur in {"USD", "US$", "$"} else "EUR" if cur in {"EUR", "€"} else "PHP"
        if best is None or value > best[0]:
            best = (value, currency)
    return best if best else (None, None)


_WORD_NUM = {"fifteen": 15, "thirty": 30, "forty-five": 45, "sixty": 60, "ninety": 90, "seven": 7, "ten": 10}


def classify_payment(text: str) -> str | None:
    lower = _clean(text).lower()
    if not lower:
        return None
    if re.search(r"milestones?|tranches?|installments?|progress billing", lower) or len(
        re.findall(r"\d+\s*%|\bpercent\b|\(\d+%\)", lower)
    ) >= 2:
        return "Milestone-based"
    days = re.search(
        r"(?:within|net|not later than)\s+(?:([a-z\-]+)\s+)?\(?(\d{1,3})\)?\s*(?:calendar\s+|business\s+|banking\s+|working\s+)?days",
        lower,
    )
    if days:
        return f"{int(days.group(2))} Days"
    for word, num in _WORD_NUM.items():
        if re.search(rf"\bnet\s+{word}\b|within\s+{word}\s+(?:calendar\s+)?days", lower):
            return f"{num} Days"
    if re.search(r"\bmonthly\b|each (?:calendar )?month|every month|per month", lower):
        return "Monthly"
    if re.search(r"upfront|up-front|in full upon signing|due on receipt|full payment in advance", lower):
        return "Upfront"
    return _truncate_sentences(text, 180)


def classify_renewal(text: str) -> str | None:
    lower = _clean(text).lower()
    if not lower:
        return None
    if re.search(r"(?<!no )(?<!not )automatic(?:ally)?\s+(?:be\s+)?renew|auto-?renew|renewed automatically|tacitly renewed", lower):
        if re.search(r"\b(?:six|6)\b\W*(?:\(6\)\s*)?-?\s*months?", lower):
            return "Auto-renew (6 months)"
        return "Auto-renew (12 months)"
    if re.search(r"mutual(?:ly)?\s+(?:written\s+)?agree|upon (?:the )?(?:written )?agreement|may be renewed|renewable upon|subject to (?:a )?(?:new )?written", lower):
        return "Manual renewal only"
    if re.search(r"shall not be renewed|no renewal|not be extended|shall not renew|non-renewable|cannot be renewed", lower):
        return "No renewal"
    return _truncate_sentences(text, 240)


def _role_kind(role: str) -> str | None:
    role = role.lower().strip()
    if any(r == role or r in role for r in VENDOR_ROLES):
        return "vendor"
    if any(r == role or r in role for r in CLIENT_ROLES):
        return "client"
    return None


PARTY_NAME_RE = re.compile(
    rf"^\s*(?:and\s+)?(.+?(?:,\s*{COMPANY_SUFFIX})?)\s*,\s*(?:a|an|with|duly|organized|existing|whose|located|represented|doing)\b",
    re.I,
)
ROLE_RE = re.compile(
    r"hereinafter\s+(?:referred\s+to\s+as|called|known\s+as|designated\s+as)\s+(?:the\s+)?[\"']?([A-Za-z][A-Za-z ]{1,40}?)[\"']?\s*(?:[;,.)]|$)",
    re.I,
)
ROLE_PAREN_RE = re.compile(r"\((?:the\s+|hereinafter\s+)?[\"']([A-Za-z][A-Za-z ]{1,30})[\"']\)")
ADDRESS_RE = re.compile(
    r"(?:principal\s+(?:office|place\s+of\s+business)|office\s+address|business\s+address|registered\s+office|offices?|address)\s+(?:at|in|is|located\s+at)\s+(.+?)(?=,\s*(?:represented|herein|hereinafter|acting|through|and\s+represented)\b|;|\s+\(|$)",
    re.I,
)
REP_RE = re.compile(
    r"represented\s+(?:herein\s+|in\s+this\s+act\s+)?by\s+(?:its\s+)?(?P<title>[A-Z][\w &\-]+?),\s*(?:Mr\.|Ms\.|Mrs\.|Atty\.|Engr\.|Dr\.)?\s*(?P<name>[A-Z][A-Za-z.\-' ]+?)\s*,",
)
REP_RE_ALT = re.compile(
    r"represented\s+(?:herein\s+)?by\s+(?:Mr\.|Ms\.|Mrs\.|Atty\.|Engr\.|Dr\.)?\s*(?P<name>[A-Z][A-Za-z.\-' ]+?),\s*its\s+(?P<title>[A-Z][\w &\-]+?)\s*[,;]",
)


def _party_segments(preamble: list[str]) -> list[tuple[str, str]]:
    """(segment text, role) pairs; one paragraph may introduce both parties."""
    segments = []
    for para in preamble:
        flat = _clean(para)
        matches = list(ROLE_RE.finditer(flat)) or list(ROLE_PAREN_RE.finditer(flat))
        start = 0
        for m in matches:
            segment = flat[start : m.end()].lstrip(" ;,-")
            segment = re.sub(r"^and\s+", "", segment, flags=re.I)
            segments.append((segment, m.group(1)))
            start = m.end()
    return segments


def parse_parties(preamble: list[str]) -> dict[str, dict[str, str | None]]:
    parties: dict[str, dict[str, str | None]] = {}
    for flat, role in _party_segments(preamble):
        kind = _role_kind(role)
        if not kind or kind in parties:
            continue
        name_m = PARTY_NAME_RE.match(flat)
        addr_m = ADDRESS_RE.search(flat)
        rep_m = REP_RE.search(flat) or REP_RE_ALT.search(flat)
        name = smart_case(_clean(name_m.group(1))) if name_m else None
        if name:
            name = re.split(r"\b(?:by and )?between\s*:\s*|\bby and between\s+", name, flags=re.I)[-1].strip()
        signatory = None
        if rep_m:
            signatory = f"{smart_case(_clean(rep_m.group('name')))}, {smart_case(_clean(rep_m.group('title')))}"
        parties[kind] = {
            "name": name,
            "address": _clean(addr_m.group(1)).rstrip(" ,.") if addr_m else None,
            "signatory": signatory,
            "role": role.strip().upper(),
        }
    return parties


NAME_LINE_RE = re.compile(r"^(?:Mr\.|Ms\.|Mrs\.|Atty\.|Engr\.|Dr\.)?\s*[A-Z][A-Za-z.'\-]*(?:\s+[A-Z][A-Za-z.'\-]*){1,4}$")


def parse_signature_block(lines: list[str], parties: dict) -> dict[str, str]:
    known = {str(p.get("name") or "").lower() for p in parties.values()}
    out: dict[str, str] = {}
    role_kind = None
    stage = None
    name = None
    for raw in lines:
        line = _clean(raw)
        if not line:
            continue
        role_m = re.match(r"^For\s+(?:and\s+on\s+behalf\s+of\s+)?(?:the\s+)?([A-Za-z ]+?)\s*:?$", line, flags=re.I)
        if role_m and _role_kind(role_m.group(1)):
            role_kind = _role_kind(role_m.group(1))
            stage, name = "company", None
            continue
        if role_kind is None or role_kind in out:
            continue
        if re.match(r"^By\s*:", line, flags=re.I):
            rest = _clean(re.sub(r"^By\s*:\s*_*", "", line, flags=re.I))
            stage = "name"
            if rest and NAME_LINE_RE.match(rest):
                name, stage = smart_case(rest), "title"
            continue
        if set(line) <= set("_ ") or line.lower() in known or re.search(rf"\b{COMPANY_SUFFIX}$", line, flags=re.I):
            continue
        if re.match(r"^(?:Name|Signature)\s*:", line, flags=re.I):
            line = _clean(line.split(":", 1)[1])
        if stage in {"company", "name"} and NAME_LINE_RE.match(line) and not re.search(r"MANAGEMENT|SERVICES|LOGISTICS|TRADING", line):
            name, stage = smart_case(line), "title"
            continue
        if stage == "title" and name:
            title = re.sub(r"^(?:Title|Designation|Position)\s*:\s*", "", line, flags=re.I)
            out[role_kind] = f"{name}, {smart_case(title)}"
            stage = None
    return out


def parse_title(sections: list[Section]) -> str | None:
    for section in sections:
        if section.kind != "preamble":
            continue
        for para in section.paragraphs:
            m = re.search(
                r"\bThis\s+(.{8,180}?)\s*\((?:the|this|hereinafter(?:\s+referred\s+to\s+as)?(?:\s+the)?)?\s*[\"']?(?:Agreement|Contract|Lease|MSA)[\"']?\)",
                _clean(para),
            ) or re.search(r"\bThis\s+(.{8,160}?)\s+is\s+(?:made|entered|executed)", _clean(para))
            if m:
                return smart_case(m.group(1).strip(" ,"))
    for section in sections:
        if section.kind not in {"cover", "preamble"}:
            continue
        for para in section.paragraphs:
            if re.search(r"\b(AGREEMENT|CONTRACT|LEASE|MEMORANDUM)\b", para) and para.isupper() and 12 < len(para) < 180:
                return smart_case(para)
    return None


def parse_signed_date(sections: list[Section]) -> str | None:
    for section in sections:
        if section.kind not in {"preamble", "signature"}:
            continue
        for para in section.paragraphs:
            if re.search(r"made and entered|executed|signed|entered into|set their hands|this\s+\d{1,2}(?:st|nd|rd|th)\s+day", para, flags=re.I):
                dates = ordered_dates(para)
                if dates:
                    return dates[0]
    return None


# --------------------------------------------------------------------------
# Entry point
# --------------------------------------------------------------------------

def extract_long_form(paragraphs: list[str], text: str, model) -> dict | None:
    sections = split_sections(paragraphs)
    if not is_long_form(sections, text):
        return None

    preamble = [p for s in sections if s.kind == "preamble" for p in s.paragraphs]
    signature = [p for s in sections if s.kind == "signature" for p in s.paragraphs]
    routed, scanned = _route(sections, model)
    out: dict = {}

    title = parse_title(sections)
    if title:
        out["contract_title"] = title
        out["contract_type"] = extract_type(title)

    parties = parse_parties(preamble)
    client = parties.get("client", {})
    vendor = parties.get("vendor", {})
    out["client_name"] = client.get("name")
    out["vendor_name"] = vendor.get("name")
    out["vendor_address"] = vendor.get("address")

    signers = parse_signature_block(signature, parties)
    out["client_signatory"] = signers.get("client") or client.get("signatory")
    out["vendor_signatory"] = signers.get("vendor") or vendor.get("signatory")
    out["signed_date"] = parse_signed_date(sections)

    clauses = []
    if "dates" in routed:
        text_, heading, conf = routed["dates"]
        dates = ordered_dates(text_)
        if dates:
            out["start_date"] = dates[0]
            later = [d for d in dates[1:] if d > dates[0]]
            out["end_date"] = later[0] if later else None
        clauses.append(("dates", text_, heading, conf))

    if "value" in routed:
        text_, heading, conf = routed["value"]
        value, currency = parse_money(text_)
        if value is not None:
            out["contract_value"] = value
            out["currency"] = currency
        clauses.append(("value", text_, heading, conf))

    if "payment" in routed:
        text_, heading, conf = routed["payment"]
        out["payment_terms"] = classify_payment(text_)
        clauses.append(("payment", text_, heading, conf))

    if "renewal" in routed:
        text_, heading, conf = routed["renewal"]
        out["renewal_terms"] = classify_renewal(text_)
        clauses.append(("renewal", text_, heading, conf))

    for field_name, key, limit in (
        ("scope", "scope", 700),
        ("penalty", "penalty_clause", 500),
        ("termination", "termination_clause", 600),
    ):
        if field_name in routed:
            text_, heading, conf = routed[field_name]
            out[key] = _truncate_sentences(text_, limit)
            clauses.append((field_name, text_, heading, conf))

    out["clauses"] = [
        {"label": label, "confidence": conf, "section": heading, "text": _truncate_sentences(t, 400)}
        for label, t, heading, conf in clauses
    ]
    out["long_form"] = {
        "articles": sum(1 for s in sections if s.kind == "article"),
        "whereas_clauses": sum(1 for s in sections if s.kind == "recitals" for p in s.paragraphs if p.upper().startswith("WHEREAS")),
        "paragraphs_total": sum(len(s.paragraphs) for s in sections),
        "paragraphs_scanned": scanned,
        "paragraphs_used": len(clauses),
        "ignored_sections": sorted({s.kind for s in sections if s.kind in {"cover", "toc", "recitals", "acknowledgment", "annex"}}),
    }
    return {k: v for k, v in out.items() if v not in (None, "", [])}
