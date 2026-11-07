"""Diverse clause examples for training — not locked to the demo PDFs."""
from __future__ import annotations

import json
import random
import re
from pathlib import Path
from typing import Iterable

RNG = random.Random(42)

CLIENTS = [
    "JBC Events Management",
    "Harbour View Properties Inc.",
    "Northwind Retail Corp.",
    "Laguna Medical Center",
    "Pacific Grain Traders",
    "MetroLink Transit Authority",
    "Sunrise Hotels Group",
    "Batangas Port Services",
]
VENDORS = [
    "Grandeur Event Furnishings Co.",
    "MesaNueva Catering Services Inc.",
    "Nightwatch Event Security Inc.",
    "Apex Production Crew Inc.",
    "LumenStage AV Trading Corp.",
    "RapidMove Event Transport Inc.",
    "StowWell Logistics Corp.",
    "Marquee Pavilion Venues Inc.",
    "BrightFloor Janitorial Cooperative",
    "SteelHarbor Trading Corp.",
]
ADDRESSES = [
    "88 Katipunan Avenue, Quezon City, Metro Manila, Philippines",
    "14 Pioneer Street, Mandaluyong City, Philippines",
    "5th Avenue, Bonifacio Global City, Taguig, Philippines",
    "Lot 9, FTI Complex, Taguig City, Philippines",
    "42 Scout Torillo Street, Quezon City, Philippines",
    "3rd Floor, 6750 Building, Ayala Avenue, Makati City, Philippines",
    "Cebu Business Park, Cebu City, Philippines",
]
TITLES = [
    "Event Furniture and Staging Supply Agreement 2026",
    "Banquet Catering Services Agreement 2026",
    "Grand Ballroom Lease Agreement 2026",
    "Managed IT Support Master Agreement",
    "Commercial Cleaning Services Contract",
    "Medical Supplies Distribution Agreement",
    "Warehouse Cold Storage Lease",
    "Security and Crowd Control Services Agreement",
]
SIGNATORIES = [
    "Patricia J. Bernal, Managing Director",
    "Carlo M. Reyes, Operations Manager",
    "Nina S. Domingo, Procurement Officer",
    "Rafael D. Lim, General Manager",
    "Isabelle G. Tan, Leasing Director",
    "Chef Lorenzo P. Villanueva, Principal",
]


def _pick(items: list[str]) -> str:
    return RNG.choice(items)


def _amt() -> str:
    return f"{RNG.choice([920000, 1250000, 1680000, 2180000, 2850000, 3450000, 4200000]):,}.00"


def _pct() -> str:
    return RNG.choice(["1", "1.5", "2", "3", "4", "5"])


def _days() -> str:
    return RNG.choice(["5", "7", "15", "30", "45", "60", "90"])


def _date() -> str:
    y = RNG.choice(["2025", "2026", "2027"])
    m = f"{RNG.randint(1, 12):02d}"
    d = f"{RNG.randint(1, 28):02d}"
    style = RNG.randint(0, 3)
    if style == 0:
        return f"{y}-{m}-{d}"
    months = ["January", "February", "March", "April", "May", "June", "July", "August", "September", "October", "November", "December"]
    if style == 1:
        return f"{months[int(m) - 1]} {int(d)}, {y}"
    if style == 2:
        return f"{int(m)}/{int(d)}/{y}"
    return f"{int(d)} {months[int(m) - 1]} {y}"


def _many(templates: Iterable[str], n: int) -> list[str]:
    pool = list(templates)
    out: list[str] = []
    for _ in range(n):
        t = _pick(pool)
        out.append(
            t.format(
                client=_pick(CLIENTS),
                vendor=_pick(VENDORS),
                addr=_pick(ADDRESSES),
                title=_pick(TITLES),
                sign=_pick(SIGNATORIES),
                amt=_amt(),
                pct=_pct(),
                days=_days(),
                date=_date(),
                n=_days(),
            )
        )
    return out


def generate_dataset() -> list[tuple[str, str]]:
    rows: list[tuple[str, str]] = []

    titles = _many(
        [
            "Contract Title: {title}",
            "Title of Agreement: {title}",
            "This {title} sets out the terms of engagement.",
            "AGREEMENT TITLE: {title}",
            "{title}",
            "The parties enter into the {title}.",
        ],
        70,
    )
    rows += [(t, "title") for t in titles]

    types = _many(
        [
            "Contract Type: Supply Agreement",
            "Contract Type: Service Agreement",
            "Contract Type: Lease Agreement",
            "This is a supply agreement for goods.",
            "This service agreement covers professional services.",
            "This lease agreement covers use of premises and equipment.",
            "Type of contract: Maintenance Contract",
            "The agreement type is Consulting Contract.",
            "Agreement Type: Supply Agreement",
        ],
        70,
    )
    rows += [(t, "type") for t in types]

    parties = _many(
        [
            "Client Name: {client}\nVendor Name: {vendor}\nVendor Address: {addr}",
            "CLIENT: {client}\nVENDOR: {vendor}, with principal offices at {addr}",
            "This Agreement is entered into by {client} (\"Client\") and {vendor} (\"Vendor\") of {addr}.",
            "Between {client} as Client and {vendor} as Vendor, whose address is {addr}.",
            "The Client is {client}. The Vendor/Supplier is {vendor} located at {addr}.",
            "Parties: {client} (Client) and {vendor} (Contractor), office at {addr}.",
        ],
        90,
    )
    rows += [(t, "parties") for t in parties]

    dates = _many(
        [
            "Effective Date: {date}\nEnd Date: {date}",
            "The term commences on {date} and expires on {date}.",
            "Start date {date}. Termination date {date}.",
            "This Agreement shall be effective as of {date} and continue until {date}.",
            "Commencement: {date}. Expiry: {date}.",
            "Signed Date: {date}",
        ],
        80,
    )
    rows += [(t, "dates") for t in dates]

    scope = _many(
        [
            "Scope of Work: {vendor} shall perform the contracted works for {client} at locations designated by Client, including delivery, installation, and documentation.",
            "Vendor shall provide daily services, periodic inspections, and monthly reports covering the statement of work.",
            "The services include staging, on-site crew, and haul-out after each event according to the production calendar.",
            "SOW: supply of goods in batches based on the approved procurement schedule, with serial-number inventories.",
            "Description: lease of space including security, utilities within cap, and access logs.",
            "The contractor will deliver managed support, monitoring, and executive summaries.",
        ],
        80,
    )
    rows += [(t, "scope") for t in scope]

    payment = _many(
        [
            "Payment Terms: Net 30",
            "Payment Terms: Net 15 after approved invoice.",
            "Invoices are due net thirty (30) days from receipt.",
            "Billing shall be monthly in arrears.",
            "Payment is milestone-based against accepted deliverables.",
            "Fees are payable upfront upon signing.",
            "The Client shall pay within 60 days of invoice.",
            "Payment Terms: Monthly",
        ],
        80,
    )
    rows += [(t, "payment") for t in payment]

    value = _many(
        [
            "Contract Value: PHP {amt}",
            "Total contract value is PHP {amt}.",
            "The consideration is {amt} Philippine Pesos.",
            "Amount: PHP {amt} Currency: PHP",
            "Contract Value: USD 250000.00",
            "The total obligation is ₱{amt}.",
        ],
        70,
    )
    rows += [(t, "value") for t in value]

    penalty = _many(
        [
            "Penalty Clause: Late delivery beyond {n} calendar days incurs a {pct}% deduction per week from the affected batch value.",
            "Liquidated damages of {pct}% of the overdue amount shall apply for each week of delay.",
            "A late fee of {pct} percent per month will be charged on unpaid rent.",
            "If shipment is delayed more than {n} days, Vendor shall pay a penalty of {pct}% of that milestone.",
            "Unfilled posts incur a {pct}% credit of that day's fee.",
            "Failed SLA more than twice in a month incurs a {pct}% service credit.",
            "Late rental payment beyond {n} days incurs a {pct}% surcharge plus 0.1% per day thereafter.",
        ],
        90,
    )
    rows += [(t, "penalty") for t in penalty]

    renewal = _many(
        [
            "Renewal Terms: Auto-renew (12 months) unless either party gives {days} days written notice of non-renewal.",
            "This Agreement shall automatically renew for successive twelve-month periods.",
            "The contract auto-renews for 6 months if no notice is given.",
            "Manual renewal only, subject to performance evaluation and mutual written agreement.",
            "There shall be no automatic renewal. Renewal requires a new written instrument.",
            "Renewal Terms: No renewal. Any extension requires a written change order.",
            "Upon expiry the parties may renew by written agreement only.",
        ],
        90,
    )
    rows += [(t, "renewal") for t in renewal]

    termination = _many(
        [
            "Termination Clause: Either party may terminate for uncured material breach after {days} days written notice.",
            "Client may terminate for convenience with {days} days written notice and payment through the notice period.",
            "Either party may terminate with {days} days written notice for material breach not cured within 15 days from notice.",
            "TERM AND TERMINATION: Client may terminate immediately for loss of license.",
            "This Agreement may be ended by either party on {days} days' prior written notice.",
            "Early termination requires {days} days notice and a residual fee.",
        ],
        90,
    )
    rows += [(t, "termination") for t in termination]

    signatures = _many(
        [
            "Client Authorized Signatory: {sign}\nVendor Authorized Signatory: {sign}\nSigned Date: {date}",
            "IN WITNESS WHEREOF, {sign} signed for Client and {sign} signed for Vendor on {date}.",
            "SIGNATORY (CLIENT): {sign}\nSIGNATORY (VENDOR): {sign}\nSIGNED DATE: {date}",
            "For the Client: {sign}. For the Vendor: {sign}. Date of signing: {date}.",
            "IN WITNESS WHEREOF the parties have executed this Agreement as of {date}. Client: {sign}. Vendor: {sign}.",
            "Signed for the Lessee by {sign} and for the Lessor by {sign} on {date}.",
        ],
        90,
    )
    rows += [(t, "signatures") for t in signatures]

    other = _many(
        [
            "CONFIDENTIALITY: Each party shall protect the other's confidential information for twenty-four months after termination.",
            "GOVERNING LAW: Laws of the Republic of the Philippines.",
            "DISPUTE RESOLUTION: Good faith negotiation then mediation in Makati.",
            "INDEMNITY: Vendor indemnifies Client against third-party claims arising from Vendor negligence.",
            "LIMITATION OF LIABILITY: Aggregate liability shall not exceed fees paid in the preceding twelve months.",
            "DATA PROTECTION: Vendor shall process personal data only under Client instructions.",
            "INSURANCE: Vendor maintains general liability insurance naming Client as certificate holder.",
            "ENTIRE AGREEMENT: This document and exhibits constitute the entire agreement.",
            "RECITALS WHEREAS Client requires services and Vendor represents it is qualified.",
            "FORCE MAJEURE: Neither party is liable for delays caused by events beyond reasonable control.",
            "NOTICES shall be in writing and deemed received three days after posting to the address above.",
            "SEVERABILITY: If any provision is held invalid the remainder remains in force.",
        ],
        110,
    )
    rows += [(t, "other") for t in other]

    rows += generate_unlabeled_clauses()
    rows += generate_longform_clauses()
    extra = load_extra_examples()
    if extra:
        print(f"Loaded {len(extra)} extra labeled examples from extra_examples.jsonl")
    rows += extra
    harvested = harvest_from_sample_pdfs()
    if harvested:
        print(f"Loaded {len(harvested)} labeled lines from sample PDFs")
    rows += harvested

    RNG.shuffle(rows)
    return rows


ALLOWED_LABELS = {
    "title",
    "type",
    "parties",
    "dates",
    "scope",
    "payment",
    "value",
    "penalty",
    "renewal",
    "termination",
    "signatures",
    "other",
}


def load_extra_examples() -> list[tuple[str, str]]:
    """Optional hand-labeled clauses: nlp/extra_examples.jsonl  {"text": "...", "label": "parties"}"""
    path = Path(__file__).resolve().parent / "extra_examples.jsonl"
    if not path.is_file():
        return []
    rows: list[tuple[str, str]] = []
    for line_no, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), start=1):
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        try:
            item = json.loads(line)
        except json.JSONDecodeError as exc:
            print(f"Skipping extra_examples.jsonl line {line_no}: {exc}")
            continue
        text = str(item.get("text") or "").strip()
        label = str(item.get("label") or "").strip().lower()
        if not text or label not in ALLOWED_LABELS:
            print(f"Skipping extra_examples.jsonl line {line_no}: need text and label in {sorted(ALLOWED_LABELS)}")
            continue
        rows.append((text, label))
    return rows


_PDF_LINE_LABELS = (
    (r"contract title\s*:", "title"),
    (r"agreement title\s*:", "title"),
    (r"contract type\s*:", "type"),
    (r"agreement type\s*:", "type"),
    (r"client name\s*:", "parties"),
    (r"vendor name\s*:", "parties"),
    (r"supplier name\s*:", "parties"),
    (r"vendor address\s*:", "parties"),
    (r"effective date\s*:", "dates"),
    (r"end date\s*:", "dates"),
    (r"signed date\s*:", "signatures"),
    (r"scope of work\s*:", "scope"),
    (r"payment terms\s*:", "payment"),
    (r"contract value\s*:", "value"),
    (r"penalty clause\s*:", "penalty"),
    (r"renewal terms\s*:", "renewal"),
    (r"termination clause\s*:", "termination"),
    (r"client authorized signatory\s*:", "signatures"),
    (r"vendor authorized signatory\s*:", "signatures"),
)


def harvest_from_sample_pdfs() -> list[tuple[str, str]]:
    """Train on the actual PDF wording (dashes, wrapped lines), not only clean templates."""
    samples = Path(__file__).resolve().parent.parent / "samples"
    if not samples.is_dir():
        return []
    try:
        from pypdf import PdfReader
    except ImportError:
        return []

    rows: list[tuple[str, str]] = []
    seen: set[str] = set()
    for pdf in samples.rglob("*.pdf"):
        try:
            reader = PdfReader(str(pdf))
            raw = "\n".join((page.extract_text() or "") for page in reader.pages)
        except Exception:
            continue
        for original in raw.splitlines():
            line = re.sub(r"\s+", " ", original).strip(" -")
            if len(line) < 8:
                continue
            for pattern, label in _PDF_LINE_LABELS:
                if re.search(pattern, line, flags=re.I):
                    key = f"{label}|{line.lower()}"
                    if key in seen:
                        break
                    seen.add(key)
                    rows.append((line, label))
                    rows.append((f"- {line}", label))
                    break
    return rows


def generate_unlabeled_clauses() -> list[tuple[str, str]]:
    """Free-form legal wording so the classifier works on PDFs without template labels."""
    rows: list[tuple[str, str]] = []
    titles = _many(
        [
            "THIS {title} is entered into as of {date}.",
            "MEMORANDUM OF AGREEMENT — {title}",
            "KNOW ALL MEN BY THESE PRESENTS: this {title}",
            "{title} (the \"Agreement\")",
            "SERVICE CONTRACT: {title}",
            "LEASE CONTRACT covering {title}",
        ],
        80,
    )
    rows += [(t, "title") for t in titles]

    types = _many(
        [
            "The parties agree this instrument is a contract for services.",
            "This document is a lease of premises and equipment.",
            "Goods will be supplied under this purchase and supply arrangement.",
            "Professional consulting services are the subject of this engagement.",
            "Preventive maintenance is the principal obligation under this contract.",
            "Crowd control and security services are hereby engaged.",
            "This is a master services agreement for recurring work.",
        ],
        70,
    )
    rows += [(t, "type") for t in types]

    parties = _many(
        [
            "THIS AGREEMENT is made between {client} (\"Client\") and {vendor} (\"Vendor\"), with offices at {addr}.",
            "by and between {client}, hereinafter referred to as the CLIENT, and {vendor}, hereinafter referred to as the VENDOR, of {addr}.",
            "The Lessee is {client}. The Lessor is {vendor}, whose principal place of business is {addr}.",
            "Party A ({client}) and Party B ({vendor} of {addr}) agree as follows.",
            "BUYER: {client}  SELLER: {vendor}  ADDRESS OF SELLER: {addr}",
            "{client} (the Company) engages {vendor} (the Contractor) located at {addr}.",
            "This Agreement is executed by {client} as procuring entity and {vendor} as supplier, office at {addr}.",
        ],
        120,
    )
    rows += [(t, "parties") for t in parties]

    dates = _many(
        [
            "The term begins {date} and expires {date}.",
            "from {date} to {date}",
            "commencing on {date} until {date}",
            "The lease period is {date} through {date}.",
            "Duration: {date} – {date}",
            "This contract shall take effect on {date} and remain in force until {date}.",
            "Validity period {date} to {date} inclusive.",
        ],
        100,
    )
    rows += [(t, "dates") for t in dates]

    scope = _many(
        [
            "{vendor} shall furnish all labor, materials, and supervision required to complete the works for {client}.",
            "The Contractor's duties include on-site deployment, incident reporting, and demobilization after each event.",
            "Services cover licensed officers, bag checks, VIP lanes, and post-event incident logs.",
            "Vendor will deliver, install, and retrieve equipment according to the event calendar issued by Client.",
            "The leased premises include warehouse floor, loading dock access, and 24-hour entry logs.",
            "Work includes menu production, service staff, kitchen set-up, and pull-out.",
        ],
        90,
    )
    rows += [(t, "scope") for t in scope]

    payment = _many(
        [
            "Invoices are payable within thirty (30) days from receipt.",
            "Net 45 days from the invoice date.",
            "Fees are billed monthly in arrears and due on the fifth business day.",
            "Payment shall be made in three milestones against accepted deliverables.",
            "Fifty percent is due upon signing and the balance is due on delivery.",
            "The Client shall settle all invoices not later than 15 days after billing.",
            "Compensation is due net fifteen days.",
        ],
        90,
    )
    rows += [(t, "payment") for t in payment]

    value = _many(
        [
            "for and in consideration of the sum of PHP {amt}",
            "total fee of ₱{amt}",
            "The contract price is Php {amt} inclusive of VAT.",
            "Client shall pay Vendor the amount of {amt} Philippine Pesos.",
            "Aggregate contract price: PHP{amt}",
            "Consideration amounting to PHP {amt} only.",
            "The rent is PHP {amt} for the full term.",
        ],
        90,
    )
    rows += [(t, "value") for t in value]

    penalty = _many(
        [
            "Liquidated damages at {pct}% of the affected fee shall accrue for each week of delay.",
            "A surcharge of {pct} percent per month applies to overdue amounts.",
            "Delay beyond {n} days entitles Client to a service credit of {pct}%.",
            "Uncured SLA failures trigger a {pct}% deduction from that month's invoice.",
            "Late delivery of more than {n} calendar days incurs {pct}% of the batch value.",
        ],
        90,
    )
    rows += [(t, "penalty") for t in penalty]

    renewal = _many(
        [
            "This Agreement shall automatically renew for successive twelve (12) month terms.",
            "unless either party gives {days} days' prior written notice of non-renewal, the contract auto-renews.",
            "Renewal is subject to mutual written agreement only and is not automatic.",
            "There is no automatic extension; a new contract must be signed.",
            "The lease renews for six months if neither party objects in writing.",
        ],
        80,
    )
    rows += [(t, "renewal") for t in renewal]

    termination = _many(
        [
            "Either party may terminate this Agreement for convenience upon {days} days written notice.",
            "Client may cancel for material breach that remains uncured {days} days after notice.",
            "This contract may be pre-terminated with {days} days' notice and payment of a residual fee.",
            "Immediate termination is allowed for insolvency or loss of required licenses.",
        ],
        80,
    )
    rows += [(t, "termination") for t in termination]

    return rows


def generate_longform_clauses() -> list[tuple[str, str]]:
    """Formal multi-page contract wording (articles, recitals, notarial text)."""
    rows: list[tuple[str, str]] = []
    rows += [(t, "scope") for t in _many([
        "The {vendor} shall render the services described in Annex A for the {client}, including all labor, supervision, tools, and consumables necessary for their complete and satisfactory performance.",
        "The Lessor hereby lets and the Lessee hereby takes on lease the premises located at {addr}, together with all fixtures and parking spaces appurtenant thereto.",
        "The Contractor undertakes to deliver, install, and commission the equipment enumerated in the bill of quantities at the site designated by the {client}.",
        "The services shall consist of planning, deployment of qualified personnel, on-site supervision, and submission of completion reports after each engagement.",
        "The Supplier shall furnish brand-new goods conforming to the technical specifications, delivered in batches according to the agreed schedule.",
    ], 70)]
    rows += [(t, "dates") for t in _many([
        "This Contract shall be for a period of {n} months, commencing on {date} and ending on {date}, unless sooner terminated.",
        "The term of this Agreement shall commence on {date} and shall expire on {date}.",
        "This Agreement shall be effective from {date} to {date}, inclusive.",
        "The lease shall run for a fixed period beginning {date} and ending {date}.",
    ], 70)]
    rows += [(t, "value") for t in _many([
        "In consideration of the services, the Client shall pay the Contractor a total contract price of FOUR MILLION PESOS (PHP {amt}), inclusive of all taxes.",
        "The total rental for the entire term amounts to PHP {amt}, payable as provided below.",
        "The aggregate consideration for the goods is PHILIPPINE PESOS (PHP {amt}), which shall be fixed and not subject to escalation.",
        "For and in consideration of the faithful performance hereof, the Buyer shall pay the sum of PHP {amt} (the Contract Price).",
    ], 70)]
    rows += [(t, "payment") for t in _many([
        "The Lessee shall pay the monthly rental in advance on or before the {n}th day of each month by bank transfer.",
        "The Client shall pay each undisputed invoice within thirty (30) calendar days from receipt of a valid invoice.",
        "The Contract Price shall be paid in milestones: {pct}0% upon delivery, {pct}0% upon acceptance, and the balance upon final acceptance.",
        "The Contractor shall bill monthly in arrears and the Client shall settle each billing before the end of the following month.",
        "Payment shall be released within fifteen (15) business days after submission of the billing and acceptance report.",
    ], 80)]
    rows += [(t, "penalty") for t in _many([
        "The Supplier shall pay liquidated damages equivalent to one-tenth of one percent (0.1%) of the contract price for every day of delay.",
        "Any amount not paid when due shall bear a penalty of {pct}% per month until fully paid, without need of demand.",
        "For every post left unmanned the Contractor shall be charged a penalty of {pct}% of the monthly fee per day.",
        "Delay in delivery shall entitle the Buyer to liquidated damages of {pct}% of the value of the undelivered items per day, up to ten percent (10%) of the contract price.",
    ], 70)]
    rows += [(t, "renewal") for t in _many([
        "This Contract may be renewed only upon mutual written agreement of the parties, and implied renewal shall not apply.",
        "This Agreement shall be automatically renewed for successive periods of one (1) year unless either party gives written notice of non-renewal at least {days} days before expiry.",
        "This Agreement shall not be renewed or extended; any further requirement shall be the subject of a new contract.",
        "The Lessee shall give written notice of its intention to renew at least {days} days before the expiration of the term.",
    ], 70)]
    rows += [(t, "termination") for t in _many([
        "Either party may terminate this Agreement for material breach that remains uncured {days} days after receipt of written notice.",
        "The Client may pre-terminate this Contract for convenience upon {days} days prior written notice, paying only for services rendered.",
        "The Buyer may terminate this Agreement by written notice if the Supplier fails to deliver within {days} days after the scheduled date.",
    ], 60)]
    rows += [(t, "other") for t in _many([
        "WHEREAS, the {vendor} is a corporation duly registered with the Securities and Exchange Commission and is engaged in the business of providing similar services;",
        "WHEREAS, the Board of Directors of the {client}, in a resolution adopted on {date}, approved the award of this engagement;",
        "WHEREAS, the {client} conducted a competitive canvass and the proposal dated {date} was found to be the most advantageous;",
        "WHEREAS, the parties desire to set forth in writing the terms and conditions governing their relationship;",
        "NOW, THEREFORE, for and in consideration of the foregoing premises and the mutual covenants herein, the parties agree as follows:",
        "\"Business Day\" means any day other than a Saturday, Sunday, or public holiday in Metro Manila.",
        "\"Contract Price\" means the total amount payable under this Agreement.",
        "\"Effective Date\" means the date on which the term commences as stated herein.",
        "Headings are for convenience only and shall not affect interpretation; words in the singular include the plural.",
        "BEFORE ME, a Notary Public, personally appeared the following persons exhibiting competent evidence of identity, known to me to be the same persons who executed the foregoing instrument.",
        "The Contractor shall maintain comprehensive general liability insurance of not less than PHP {amt} per occurrence naming the Client as additional insured.",
        "The Supplier shall post a performance security of PHP {amt} equivalent to ten percent (10%) of the contract price within ten (10) days from signing.",
        "Each party represents that it is duly organized, validly existing, and has full corporate power to enter into this Agreement.",
        "The Contractor is an independent contractor and nothing herein creates an employer-employee relationship.",
        "This Agreement may be executed in counterparts, each of which shall be deemed an original.",
        "Neither party may assign this Agreement without the prior written consent of the other party.",
        "All notices shall be in writing and delivered personally, by registered mail, or by courier to the addresses stated above.",
    ], 160)]
    return rows
