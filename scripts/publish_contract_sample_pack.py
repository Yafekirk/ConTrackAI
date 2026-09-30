"""
Build 10 contract sample .txt + .pdf files matching the reference legal layout
(CONTRACT TITLE, RECITALS, clauses, SIGNATURES). Run from repo root:
  python scripts/publish_contract_sample_pack.py
Requires: pip install fpdf2
"""
from __future__ import annotations

from pathlib import Path

from fpdf import FPDF


def contract_body(
    *,
    title: str,
    contract_type: str,
    client: str,
    client_addr: str,
    vendor: str,
    vendor_addr: str,
    recital_client_need: str,
    recital_vendor_capable: str,
    effective: str,
    end: str,
    value_php: str,
    scope: str,
    payment_terms: str,
    payment_detail: str,
    service_levels: str,
    confidentiality_months: str,
    liability_cap_months: str,
    termination_convenience_days: str,
    client_signatory: str,
    client_title: str,
    vendor_signatory: str,
    vendor_title: str,
    sign_date: str,
) -> str:
    return f"""CONTRACT TITLE: {title}
CONTRACT TYPE: {contract_type}
This Agreement is entered into as of the dates stated below by and between:
CLIENT: {client}, with principal offices at {client_addr}
("Client"),
and
VENDOR: {vendor}, with principal offices at {vendor_addr}
("Vendor").
RECITALS
WHEREAS Client {recital_client_need}; and
WHEREAS Vendor represents that it has the experience, personnel, and qualifications to
perform such services;
NOW, THEREFORE, in consideration of the mutual promises below, the parties agree as
follows.
EFFECTIVE DATE: {effective}
END DATE: {end}
CONTRACT VALUE: PHP {value_php}
SCOPE OF WORK: {scope}
PAYMENT TERMS: {payment_terms}
Detailed invoicing: {payment_detail}
SERVICE LEVELS: {service_levels}
CONFIDENTIALITY: Each party shall protect the other's confidential information using
reasonable care for {confidentiality_months} months after termination.
DATA PROTECTION: Vendor shall process personal data only under Client instructions and
applicable law.
INSURANCE: Vendor maintains general liability (and specialty coverage where applicable)
naming Client as certificate holder where commercially reasonable.
INDEMNITY: Vendor indemnifies Client against third-party claims arising from Vendor
negligence, subject to customary caps aligned with twelve months of fees.
LIMITATION OF LIABILITY: Except for breaches of confidentiality, indemnities, or willful
misconduct, each party's aggregate liability shall not exceed the fees paid in the {liability_cap_months} months preceding the claim.
TERM AND TERMINATION: Either party may terminate for uncured material breach after thirty days written notice. Client may terminate for convenience with {termination_convenience_days} days notice and payment through the notice period.
GOVERNING LAW: Laws of the Republic of the Philippines.
DISPUTE RESOLUTION: Good faith negotiation for thirty days, then mediation in Makati before litigation.
ENTIRE AGREEMENT: This document and exhibits constitute the entire agreement.
SIGNATURES
CLIENT: {client}
By: __________________________ Name: {client_signatory} Title: {client_title}
Date: {sign_date}
VENDOR: {vendor}
By: __________________________ Name: {vendor_signatory} Title: {vendor_title} Date:
{sign_date}
"""


SAMPLES: list[tuple[str, dict]] = [
    (
        "reference-style-01-regional-cloud-operations",
        dict(
            title="Regional Cloud Operations and Platform Support Agreement 2026",
            contract_type="Service Agreement",
            client="ConTrack Holdings Inc.",
            client_addr="Bonifacio Global City, Taguig, Philippines",
            vendor="Stratosphere Cloud Partners Inc.",
            vendor_addr="Makati Central Business District, Philippines",
            recital_client_need="requires regional cloud operations, FinOps reporting, and quarterly architecture reviews",
            recital_vendor_capable="is certified on major public clouds and maintains 24x7 NOC coverage",
            effective="2026-06-01",
            end="2027-05-31",
            value_php="3420000.00",
            scope="Vendor shall operate Client workloads across designated regions, enforce tagging and backup policies, execute approved changes via CAB records, provide monthly cost optimization reports, and maintain runbooks for critical services.",
            payment_terms="Net 30",
            payment_detail="Vendor invoices monthly in arrears per the managed services fee table in Exhibit A; pass-through cloud charges reconciled with CSP invoices.",
            service_levels="P1 incidents acknowledged within fifteen minutes and actively worked until mitigation plan within four hours during coverage windows.",
            confidentiality_months="twenty-four",
            liability_cap_months="twelve",
            termination_convenience_days="ninety",
            client_signatory="Ana Cristina Reyes",
            client_title="Chief Operating Officer",
            vendor_signatory="Miguel Angelo Ramos",
            vendor_title="Managing Director",
            sign_date="2026-05-28",
        ),
    ),
    (
        "reference-style-02-commercial-cleaning-master",
        dict(
            title="Commercial Cleaning and Janitorial Master Services Agreement",
            contract_type="Service Agreement",
            client="Harbour View Properties Inc.",
            client_addr="Pasay City, Metro Manila, Philippines",
            vendor="BrightFloor Janitorial Cooperative",
            vendor_addr="Quezon City, Metro Manila, Philippines",
            recital_client_need="requires consistent janitorial coverage for Class A office towers",
            recital_vendor_capable="employs trained crews and maintains HSE certifications",
            effective="2026-04-01",
            end="2027-03-31",
            value_php="1185000.00",
            scope="Vendor shall provide daily cleaning, periodic floor treatments, waste segregation compliant with local rules, restroom sanitation, and monthly QA walk-throughs with corrective action logs.",
            payment_terms="Net 15",
            payment_detail="Monthly invoices supported by attendance sheets and checklist sign-offs; adjustments for scope changes only with written change orders.",
            service_levels="Escalations for hygiene incidents addressed within four business hours.",
            confidentiality_months="twelve",
            liability_cap_months="twelve",
            termination_convenience_days="sixty",
            client_signatory="Luis Fernando Torres",
            client_title="Head of Facilities",
            vendor_signatory="Patricia Anne Lim",
            vendor_title="Operations Director",
            sign_date="2026-03-20",
        ),
    ),
    (
        "reference-style-03-corporate-catering-events",
        dict(
            title="Corporate Catering and Workplace Meals Agreement",
            contract_type="Service Agreement",
            client="Innovatech Philippines Inc.",
            client_addr="Alabang, Muntinlupa City, Philippines",
            vendor="Harvest Table Catering Group",
            vendor_addr="Mandaluyong City, Metro Manila, Philippines",
            recital_client_need="requires weekday meals and occasional leadership forums",
            recital_vendor_capable="operates central kitchen licensed by local health authorities",
            effective="2026-05-15",
            end="2027-05-14",
            value_php="1680000.00",
            scope="Vendor shall prepare and serve rotating menus aligned with HR dietary surveys, manage allergens labeling, provide sanitation audits, and staff buffets for up to six executive events per year.",
            payment_terms="Net 15",
            payment_detail="Consolidated monthly billing with meal counts by site; event menus priced per approved banquet orders.",
            service_levels="Food safety incidents escalated to Client within one hour with containment steps documented.",
            confidentiality_months="twelve",
            liability_cap_months="twelve",
            termination_convenience_days="sixty",
            client_signatory="Hannah Mae Yu",
            client_title="VP People & Workplace",
            vendor_signatory="Marco Luis Bautista",
            vendor_title="Executive Chef / Owner",
            sign_date="2026-05-01",
        ),
    ),
    (
        "reference-style-04-security-access-control",
        dict(
            title="Facility Security and Electronic Access Control Agreement",
            contract_type="Service Agreement",
            client="Island Retail Malls Operator Corp.",
            client_addr="Cebu City, Philippines",
            vendor="Sentinel Integrated Security Agency",
            vendor_addr="Lahug, Cebu City, Philippines",
            recital_client_need="requires uniformed presence and monitored access at flagship mall",
            recital_vendor_capable="is licensed by PNP-SOSIA and maintains vault-grade screening protocols",
            effective="2026-07-01",
            end="2028-06-30",
            value_php="9450000.00",
            scope="Vendor shall deploy guards per post orders, coordinate CCTV desk coverage, manage contractor badges, conduct patrol logs, and submit incident briefs within SLA windows.",
            payment_terms="Net 30",
            payment_detail="Monthly billing with verified headcount and shift schedules; overtime only when pre-approved.",
            service_levels="Critical incidents escalated to Client duty manager within thirty minutes.",
            confidentiality_months="thirty-six",
            liability_cap_months="twelve",
            termination_convenience_days="one hundred twenty",
            client_signatory="Roberto Ignacio Villarin",
            client_title="General Manager",
            vendor_signatory="Elena Marie Cruz",
            vendor_title="Managing Director",
            sign_date="2026-06-18",
        ),
    ),
    (
        "reference-style-05-logistics-warehousing",
        dict(
            title="Dedicated Warehousing and Order Fulfillment Agreement",
            contract_type="Service Agreement",
            client="Island Goods Trading Corp.",
            client_addr="Parañaque City, Philippines",
            vendor="RapidLink ColdChain Logistics Inc.",
            vendor_addr="Cavite Gateway Industrial Park, Philippines",
            recital_client_need="requires bonded-capable storage and Luzon parcel throughput",
            recital_vendor_capable="operates WMS-integrated facilities with barcode workflows",
            effective="2026-09-01",
            end="2028-08-31",
            value_php="13350000.00",
            scope="Vendor shall receive inbound ASN, perform QC sampling, store ambient SKUs, pick-pack-ship per Client routing rules, and publish inventory accuracy dashboards weekly.",
            payment_terms="Net 30",
            payment_detail="Monthly fees plus activity charges per Exhibit B; fuel surcharge adjusted quarterly with notice.",
            service_levels="Outbound accuracy target 99.5% measured monthly with root cause for variances.",
            confidentiality_months="twenty-four",
            liability_cap_months="twelve",
            termination_convenience_days="one hundred eighty",
            client_signatory="Katrina Isabel Sy",
            client_title="VP Supply Chain",
            vendor_signatory="Paolo Andrea Rivera",
            vendor_title="Regional Director",
            sign_date="2026-08-22",
        ),
    ),
    (
        "reference-style-06-hvac-building-systems",
        dict(
            title="HVAC and Critical Building Systems Maintenance Agreement",
            contract_type="Service Agreement",
            client="Lakeside Medical Tower Inc.",
            client_addr="Muntinlupa City, Philippines",
            vendor="ClimateCare Engineering Services",
            vendor_addr="Las Piñas City, Philippines",
            recital_client_need="requires uptime for clinical zones and documented preventive maintenance",
            recital_vendor_capable="employs certified HVAC technicians and maintains spare parts consignment",
            effective="2026-03-01",
            end="2027-02-28",
            value_php="2230000.00",
            scope="Vendor shall execute quarterly PM on chillers and AHUs, maintain refrigerant logs, provide emergency call-outs with defined response bands, and submit compliance-ready maintenance summaries.",
            payment_terms="Net 30",
            payment_detail="Labor covered under retainer; parts billed at approved estimates prior to major replacements.",
            service_levels="Emergency tickets triaged within thirty minutes; onsite arrival within SLA bands by severity.",
            confidentiality_months="twelve",
            liability_cap_months="twelve",
            termination_convenience_days="ninety",
            client_signatory="Dr. Irene Santos Fabros",
            client_title="Chief Operating Officer",
            vendor_signatory="Joseph Karl Tan",
            vendor_title="Service Manager",
            sign_date="2026-02-18",
        ),
    ),
    (
        "reference-style-07-marketing-creative-production",
        dict(
            title="Integrated Marketing Creative and Production Services Agreement",
            contract_type="Service Agreement",
            client="Sunrise Hospitality Brands",
            client_addr="Rockwell Center, Makati City, Philippines",
            vendor="Chromatic Studios Collective Inc.",
            vendor_addr="Ortigas Center, Pasig City, Philippines",
            recital_client_need="requires campaign creative, studio shoots, and localized adaptations",
            recital_vendor_capable="maintains producers, editors, and brand guardianship workflows",
            effective="2026-02-01",
            end="2027-01-31",
            value_php="4120000.00",
            scope="Vendor shall deliver creative concepts, production schedules, shoot supervision, post-production, adaptation toolkits, and DAM uploads according to Client brand manuals.",
            payment_terms="Net 45",
            payment_detail="Milestone billing aligned to creative gates in Exhibit C; usage rights limited to approved channels and term.",
            service_levels="Creative revisions tracked within agreed rounds per sprint board.",
            confidentiality_months="thirty-six",
            liability_cap_months="twelve",
            termination_convenience_days="sixty",
            client_signatory="Geraldine Mei Ho",
            client_title="Brand Director",
            vendor_signatory="Allan Vincent Dee",
            vendor_title="Executive Producer",
            sign_date="2026-01-25",
        ),
    ),
    (
        "reference-style-08-training-org-development",
        dict(
            title="Leadership Development and Organizational Consulting Agreement",
            contract_type="Consulting Agreement",
            client="BlueWave Logistics PH",
            client_addr="Taguig City, Philippines",
            vendor="Elevate HR Consulting Group",
            vendor_addr="Makati City, Philippines",
            recital_client_need="requires leadership pipelines and culture diagnostics across hubs",
            recital_vendor_capable="fields IO psychologists and certified coaches under one practice",
            effective="2026-08-01",
            end="2027-07-31",
            value_php="3875000.00",
            scope="Vendor shall deliver diagnostics, workshop series, 360 feedback cycles, coaching pods, and governance updates with anonymized workforce analytics.",
            payment_terms="Milestone",
            payment_detail="Forty percent upon kick-off, thirty percent mid-program, thirty percent upon final outcomes report.",
            service_levels="Critical escalations on psychological safety matters routed within one business day.",
            confidentiality_months="sixty",
            liability_cap_months="twelve",
            termination_convenience_days="forty-five",
            client_signatory="Vincent Paolo Padilla",
            client_title="Chief People Officer",
            vendor_signatory="Sophia Isabel Ang",
            vendor_title="Principal Consultant",
            sign_date="2026-07-10",
        ),
    ),
    (
        "reference-style-09-medical-supplies-distribution",
        dict(
            title="Medical Supplies Distribution and Replenishment Agreement",
            contract_type="Supply Agreement",
            client="MetroCare Clinic Network Inc.",
            client_addr="Mandaluyong City, Philippines",
            vendor="MedLink Distribution Partners Inc.",
            vendor_addr="Laguna Technopark, Binan, Laguna, Philippines",
            recital_client_need="requires FDA-traceable replenishment across outpatient sites",
            recital_vendor_capable="maintains cold-chain SOPs and lot-tracked ERP integrations",
            effective="2026-05-01",
            end="2027-04-30",
            value_php="5180000.00",
            scope="Vendor shall fulfill PAR-level replenishment, manage recalls, rotate lots near expiry, and produce audit-ready traceability extracts.",
            payment_terms="Net 30",
            payment_detail="Invoices reference ASN lines; discrepancies flagged within ten calendar days.",
            service_levels="Recall notices acknowledged within two hours during business days.",
            confidentiality_months="twenty-four",
            liability_cap_months="twelve",
            termination_convenience_days="ninety",
            client_signatory="Dr. Nina Claire Ocampo",
            client_title="Chief Medical Operations Officer",
            vendor_signatory="Ramon Jude Mercado",
            vendor_title="Director of Compliance",
            sign_date="2026-04-18",
        ),
    ),
    (
        "reference-style-10-conference-exhibition-management",
        dict(
            title="Annual Summit Conference and Exhibition Production Agreement",
            contract_type="Service Agreement",
            client="Philippine Fintech Association",
            client_addr="Makati City, Philippines",
            vendor="Pulse Events Management Studio",
            vendor_addr="Pasay City, Metro Manila, Philippines",
            recital_client_need="requires turn-key production for flagship multi-track summit",
            recital_vendor_capable="fields producers, technical directors, and registration platforms",
            effective="2026-10-01",
            end="2027-03-31",
            value_php="5920000.00",
            scope="Vendor shall produce staging, broadcast/streaming, delegate registration, sponsor fulfillment, speaker logistics, on-site safety briefings, and post-event analytics.",
            payment_terms="Milestone",
            payment_detail="Fifty percent upon signing, thirty-five percent thirty days prior to event, fifteen percent within fifteen days after close-out report.",
            service_levels="Show-critical incidents triaged within fifteen minutes during live program.",
            confidentiality_months="twenty-four",
            liability_cap_months="twelve",
            termination_convenience_days="sixty",
            client_signatory="Francis Paolo Uy",
            client_title="Executive Director",
            vendor_signatory="Nina Marie Aquino",
            vendor_title="Creative Director",
            sign_date="2026-09-05",
        ),
    ),
]


class ContractPDF(FPDF):
    def footer(self) -> None:
        self.set_y(-12)
        self.set_font("Helvetica", "I", 8)
        self.set_text_color(90, 90, 90)
        self.cell(0, 8, f"Page {self.page_no()}", align="C")


def body_to_pdf(body: str, pdf_path: Path) -> None:
    pdf = ContractPDF(format="A4")
    pdf.set_auto_page_break(auto=True, margin=18)
    pdf.set_margins(18, 18, 18)
    pdf.add_page()
    pdf.set_font("Helvetica", "", 9)
    pdf.set_text_color(25, 25, 25)
    for raw in body.splitlines():
        line = raw.strip("\r")
        if not line.strip():
            pdf.ln(4)
            continue
        # Reset x each line; word-wrap can leave x at the right margin and break multi_cell.
        # CHAR wrap avoids failure on long unbreakable runs (e.g. signature underscores).
        pdf.set_x(pdf.l_margin)
        pdf.multi_cell(pdf.epw, 4.6, line, wrapmode="CHAR")
    pdf.output(str(pdf_path))


def main() -> None:
    root = Path(__file__).resolve().parent.parent
    out_dir = root / "samples" / "contract-samples-pack"
    out_dir.mkdir(parents=True, exist_ok=True)

    for slug, kwargs in SAMPLES:
        body = contract_body(**kwargs)
        txt_path = out_dir / f"{slug}.txt"
        pdf_path = out_dir / f"{slug}.pdf"
        txt_path.write_text(body, encoding="utf-8")
        body_to_pdf(body, pdf_path)
        print(f"Wrote {txt_path.relative_to(root)}")
        print(f"Wrote {pdf_path.relative_to(root)}")


if __name__ == "__main__":
    main()
