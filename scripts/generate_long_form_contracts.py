"""
Build long-form (10+ page) demo contracts written the way real Philippine
commercial contracts are drafted: cover page, KNOW ALL MEN BY THESE PRESENTS,
many WHEREAS recitals, definitions, ~25 articles of boilerplate, signature
blocks, notarial acknowledgment, and annexes.

The fields ConTrack actually needs (title, parties, term, price, payment,
penalty, renewal, termination, signatories) are buried inside that prose,
surrounded by distractor dates and amounts, so the NLP extractor has to find
them on its own.

Run from repo root:
  nlp/.venv/Scripts/python.exe scripts/generate_long_form_contracts.py
Requires: pip install reportlab pypdf
"""
from __future__ import annotations

import json
import re
from pathlib import Path

from reportlab.lib import colors
from reportlab.lib.enums import TA_CENTER, TA_JUSTIFY
from reportlab.lib.pagesizes import A4
from reportlab.lib.styles import ParagraphStyle
from reportlab.lib.units import inch
from reportlab.pdfgen import canvas as rl_canvas
from reportlab.platypus import (
    KeepTogether,
    PageBreak,
    Paragraph,
    SimpleDocTemplate,
    Spacer,
    Table,
    TableStyle,
)

ROOT = Path(__file__).resolve().parent.parent
OUT_DIR = ROOT / "samples" / "long-form-contracts"

CLIENT = "JBC Events Management"
CLIENT_ADDR = "Unit 1203, One Corporate Centre, Julia Vargas Avenue, Ortigas Center, Pasig City, Metro Manila, Philippines"

ROMAN = [
    "I", "II", "III", "IV", "V", "VI", "VII", "VIII", "IX", "X",
    "XI", "XII", "XIII", "XIV", "XV", "XVI", "XVII", "XVIII", "XIX", "XX",
    "XXI", "XXII", "XXIII", "XXIV", "XXV", "XXVI", "XXVII", "XXVIII", "XXIX", "XXX",
]

_ONES = ["", "ONE", "TWO", "THREE", "FOUR", "FIVE", "SIX", "SEVEN", "EIGHT", "NINE", "TEN",
         "ELEVEN", "TWELVE", "THIRTEEN", "FOURTEEN", "FIFTEEN", "SIXTEEN", "SEVENTEEN",
         "EIGHTEEN", "NINETEEN"]
_TENS = ["", "", "TWENTY", "THIRTY", "FORTY", "FIFTY", "SIXTY", "SEVENTY", "EIGHTY", "NINETY"]


def _words_999(n: int) -> str:
    parts = []
    if n >= 100:
        parts.append(f"{_ONES[n // 100]} HUNDRED")
        n %= 100
    if n >= 20:
        parts.append(_TENS[n // 10] + (f"-{_ONES[n % 10]}" if n % 10 else ""))
    elif n:
        parts.append(_ONES[n])
    return " ".join(parts)


def peso_words(amount: float) -> str:
    whole = int(amount)
    cents = round((amount - whole) * 100)
    chunks = []
    for value, name in ((1_000_000_000, "BILLION"), (1_000_000, "MILLION"), (1000, "THOUSAND"), (1, "")):
        if whole >= value:
            chunks.append((_words_999(whole // value) + (f" {name}" if name else "")).strip())
            whole %= value
    text = " ".join(chunks) + " PESOS"
    if cents:
        text += f" AND {cents:02d}/100"
    return text


def php(amount: float) -> str:
    return f"PHP {amount:,.2f}"


def php_long(amount: float) -> str:
    return f"{peso_words(amount)} ({php(amount)})"


# --------------------------------------------------------------------------
# Contract data
# --------------------------------------------------------------------------

CONTRACTS = [
    {
        "slug": "long-lease-01-taguig-event-logistics-warehouse",
        "code": "JBC-LSE-2026-0147",
        "title": "Contract of Lease for the Taguig Event Logistics Warehouse",
        "short": "Contract of Lease - Taguig Warehouse",
        "contract_type": "Lease Agreement",
        "C": "LESSEE",
        "V": "LESSOR",
        "vendor": "StowWell Logistics Corp.",
        "vendor_addr": "Lot 9, FTI Complex, Western Bicutan, Taguig City, Metro Manila, Philippines",
        "vendor_sec": "CS201108452",
        "vendor_sec_date": "March 3, 2011",
        "vendor_business": "the ownership, development, and leasing of warehouse, cold-chain, and light-industrial facilities in Metro Manila and Calabarzon",
        "client_need": "a secure, climate-controlled warehouse near its southern Metro Manila venues where it can store, stage, and repair event furniture, staging decks, lighting trusses, and decorative props between engagements",
        "canvass_date": "April 22, 2026",
        "proposal_date": "May 4, 2026",
        "board_date": "June 18, 2026",
        "vendor_board_date": "June 25, 2026",
        "signed_ordinal": "20th day of July 2026",
        "signed_iso": "2026-07-20",
        "signed_place": "Makati City",
        "start_text": "August 1, 2026",
        "end_text": "July 31, 2029",
        "start_iso": "2026-08-01",
        "end_iso": "2029-07-31",
        "term_words": "three (3) years",
        "value": 7_560_000.00,
        "value_basis": "representing the aggregate monthly rentals for thirty-six (36) months as detailed in the rental schedule in Annex B",
        "client_sig": ("Patricia J. Bernal", "Managing Director"),
        "vendor_sig": ("Rafael D. Lim", "General Manager"),
        "witnesses": ("Carlo M. Reyes", "Andrea S. Villareal"),
        "notary_city": "Makati City",
        "scope_heading": "OBJECT OF THE LEASE",
        "scope": [
            "The LESSOR hereby leases to the LESSEE, and the LESSEE hereby accepts in lease from the LESSOR, Warehouse Building No. 4 of the FTI Complex, Western Bicutan, Taguig City, with a gross floor area of approximately one thousand eight hundred (1,800) square meters, together with two (2) dock-level loading bays, a mezzanine office of one hundred twenty (120) square meters, and twelve (12) exclusive parking slots (collectively, the \"Leased Premises\"), for use as an event logistics warehouse, staging area, and repair workshop.",
            "The Leased Premises shall be delivered with a working fire-suppression and sprinkler system, closed-circuit television coverage of all entry points, 24-hour perimeter security provided by the LESSOR, three-phase electrical service of not less than 400 amperes, and air-conditioning in the mezzanine office. The LESSOR shall maintain the roof, structural elements, perimeter fence, common driveways, and building systems in good working order throughout the Term.",
            "The LESSEE may install, at its own cost, pallet racking, a paint booth with appropriate ventilation, workbenches, and temporary partitions, provided that plans are submitted to the LESSOR at least fifteen (15) Business Days before installation and no structural alteration is made. All such improvements that can be removed without damage shall remain the property of the LESSEE.",
            "The LESSEE shall use the Leased Premises solely for the storage, staging, assembly, and repair of event equipment and related office work, and shall not store flammable liquids in excess of the quantities permitted by the Bureau of Fire Protection, explosives, or any hazardous substance.",
        ],
        "term_para": "The term of this Contract shall be for a period of three (3) years, commencing on August 1, 2026 and ending on July 31, 2029 (the \"Term\"), unless sooner terminated in accordance with Article XVIII. The LESSOR shall turn over possession of the Leased Premises to the LESSEE not later than five (5) Business Days before the start of the Term, free from all occupants and debris.",
        "renewal_para": "This Contract may be renewed only upon the mutual written agreement of the parties. The LESSEE shall signify in writing its intention to renew not later than ninety (90) days before the expiration of the Term, and the parties shall negotiate in good faith the rental rate and other terms of the renewal period. Failing such written agreement, this Contract shall expire at the end of the Term and implied renewal (tacita reconduccion) under Article 1670 of the Civil Code of the Philippines shall not apply.",
        "price_heading": "RENTAL AND CONTRACT PRICE",
        "payment_para": "The LESSEE shall pay the monthly rental in advance, on or before the fifth (5th) day of each calendar month, by direct bank transfer to the account designated in writing by the LESSOR. Rental for any partial month at the start or end of the Term shall be prorated on a daily basis.",
        "payment_more": [
            "The LESSEE shall issue post-dated checks or standing bank instructions covering the rentals for each lease year not later than ten (10) Business Days before the start of that lease year. The LESSOR shall issue an official receipt for every payment received.",
            "Utility charges for electricity, water, and telecommunications consumed in the Leased Premises are not included in the monthly rental and shall be paid by the LESSEE directly to the utility providers based on separate sub-meters.",
            "The monthly rental shall be TWO HUNDRED FIVE THOUSAND PESOS (PHP 205,000.00) for the first two (2) lease years and shall escalate to TWO HUNDRED TWENTY THOUSAND PESOS (PHP 220,000.00) at the start of the third lease year. The escalated rental is already reflected in the Contract Price stated in Article V.",
        ],
        "security_heading": "SECURITY DEPOSIT AND ADVANCE RENTAL",
        "security_para": "Upon signing of this Contract, the LESSEE shall pay the LESSOR a security deposit equivalent to three (3) months' rental, or SIX HUNDRED FIFTEEN THOUSAND PESOS (PHP 615,000.00), and an advance rental equivalent to two (2) months' rental, or FOUR HUNDRED TEN THOUSAND PESOS (PHP 410,000.00), to be applied to the first two (2) months of the Term. The security deposit shall answer for unpaid utilities and damage to the Leased Premises beyond ordinary wear and tear, and shall be returned without interest within sixty (60) days after the LESSEE vacates the Leased Premises.",
        "penalty_para": "Any rental not paid on its due date shall bear a penalty of three percent (3%) per month on the unpaid amount, computed from the due date until fully paid, without need of demand.",
        "penalty_more": [
            "Should the LESSEE fail to vacate the Leased Premises upon the expiration or termination of this Contract, the LESSEE shall pay the LESSOR a holdover fee equal to two hundred percent (200%) of the last monthly rental for each month or fraction thereof of continued occupancy.",
            "Should the LESSOR fail to restore electrical service, fire-protection systems, or roof integrity within seventy-two (72) hours after written notice from the LESSEE, the LESSEE shall be entitled to a rental abatement of one-thirtieth (1/30) of the monthly rental for each day of continued failure.",
        ],
        "termination_para": "Either party may terminate this Contract for a material breach by the other party that remains uncured thirty (30) days after receipt of written notice specifying the breach. The LESSEE may pre-terminate this Contract for convenience after the first lease year by giving the LESSOR not less than sixty (60) days prior written notice, in which case the advance rental shall be forfeited in favor of the LESSOR.",
        "insurance_amounts": ("TWENTY MILLION PESOS (PHP 20,000,000.00)", "FIVE MILLION PESOS (PHP 5,000,000.00)"),
        "liability_cap": "the total rentals paid by the LESSEE during the twelve (12) months immediately preceding the event giving rise to the claim",
        "vendor_obligations": [
            "Deliver the Leased Premises in good, clean, and tenantable condition, with all building systems in working order, on or before the turnover date.",
            "Undertake all major and structural repairs, including the roof, load-bearing walls, floor slab, drainage, and main electrical and plumbing lines, at its own cost.",
            "Maintain fire-protection equipment and secure the annual Fire Safety Inspection Certificate for the building.",
            "Pay real property taxes and assessments on the land and building.",
            "Respect the LESSEE's peaceful and adequate enjoyment of the Leased Premises for the entire Term.",
        ],
        "annex_a_title": "ANNEX A - DESCRIPTION OF THE LEASED PREMISES",
        "annex_a": [
            ["Item", "Description"],
            ["Building", "Warehouse Building No. 4, FTI Complex, Western Bicutan, Taguig City"],
            ["Gross floor area", "Approx. 1,800 sq. m. ground floor + 120 sq. m. mezzanine office"],
            ["Clear height", "9.0 meters to the underside of roof trusses"],
            ["Loading", "Two (2) dock-level bays with hydraulic levelers"],
            ["Power", "Three-phase, 400A; separate sub-meter"],
            ["Security", "24-hour guard, CCTV at all entry points, access logs"],
            ["Parking", "Twelve (12) exclusive slots in front of Building No. 4"],
        ],
        "annex_b_title": "ANNEX B - RENTAL SCHEDULE",
        "annex_b": [
            ["Lease Year", "Period", "Monthly Rental", "Annual Rental"],
            ["Year 1", "Aug 2026 - Jul 2027", "PHP 205,000.00", "PHP 2,460,000.00"],
            ["Year 2", "Aug 2027 - Jul 2028", "PHP 205,000.00", "PHP 2,460,000.00"],
            ["Year 3", "Aug 2028 - Jul 2029", "PHP 220,000.00", "PHP 2,640,000.00"],
            ["Total", "36 months", "", "PHP 7,560,000.00"],
        ],
        "expected": {
            "payment_terms": "Monthly",
            "renewal_terms": "Manual renewal only",
        },
    },
    {
        "slug": "long-service-01-event-production-crew-msa",
        "code": "JBC-MSA-2026-0311",
        "title": "Master Services Agreement for Event Production and Technical Crew Services",
        "short": "MSA - Event Production and Technical Crew",
        "contract_type": "Service Agreement",
        "C": "CLIENT",
        "V": "SERVICE PROVIDER",
        "vendor": "Apex Production Crew Inc.",
        "vendor_addr": "3rd Floor, Sagittarius Building, 111 H.V. Dela Costa Street, Salcedo Village, Makati City, Philippines",
        "vendor_sec": "CS201512907",
        "vendor_sec_date": "September 14, 2015",
        "vendor_business": "the provision of stage management, rigging, lighting, sound, and backstage technical crew for live events, concerts, and corporate productions",
        "client_need": "a dependable pool of trained stage managers, riggers, lighting and sound technicians, and backstage crew to support the corporate conventions, product launches, and large social events it produces throughout Luzon",
        "canvass_date": "October 5, 2026",
        "proposal_date": "October 19, 2026",
        "board_date": "November 26, 2026",
        "vendor_board_date": "December 2, 2026",
        "signed_ordinal": "15th day of December 2026",
        "signed_iso": "2026-12-15",
        "signed_place": "Pasig City",
        "start_text": "1 January 2027",
        "end_text": "31 December 2027",
        "start_iso": "2027-01-01",
        "end_iso": "2027-12-31",
        "term_words": "one (1) year",
        "value": 12_480_000.00,
        "value_basis": "covering an estimated forty-eight (48) event days as detailed in Annex B",
        "client_sig": ("Carlo M. Reyes", "Chief Operating Officer"),
        "vendor_sig": ("Jerome A. Santos", "President and Chief Executive Officer"),
        "witnesses": ("Nina S. Domingo", "Paolo V. Mendoza"),
        "notary_city": "Pasig City",
        "scope_heading": "SCOPE OF SERVICES",
        "scope": [
            "The SERVICE PROVIDER shall furnish qualified stage managers, riggers, lighting and sound technicians, and backstage crew for events produced by the CLIENT, including technical rehearsals, ingress and set-up, show operation, and egress and strike within eight (8) hours after the end of each event, in accordance with the crew call sheets issued by the CLIENT.",
            "For each event, the SERVICE PROVIDER shall assign one (1) crew chief who shall attend the CLIENT's production meeting not later than three (3) days before the event, confirm the crew list and arrival times, and serve as the single point of contact on site. The crew chief shall submit a signed event completion report, including attendance and incident logs, within two (2) Business Days after each event.",
            "All riggers deployed under this Agreement shall hold current rigging and working-at-height certifications, and all electricians shall be supervised by a licensed electrical engineer. The SERVICE PROVIDER shall supply standard personal protective equipment, hand tools, radios, and safety harnesses for its Personnel.",
            "The CLIENT may request additional crew or extended hours by written work order at least forty-eight (48) hours before the call time. Overtime shall be charged at the rates in Annex B and only when approved in writing by the CLIENT's production manager.",
        ],
        "term_para": "This Agreement shall take effect on 1 January 2027 and shall remain in force until 31 December 2027 (the \"Term\"), unless sooner terminated in accordance with Article XVIII or extended in accordance with Article IV.",
        "renewal_para": "Upon expiration of the Term, this Agreement shall automatically renew for successive twelve (12) month periods on the same terms and conditions, unless either party gives the other written notice of non-renewal at least sixty (60) days before the end of the then-current Term. Rates for any renewal period may be adjusted only by a written amendment signed by both parties.",
        "price_heading": "CONTRACT PRICE",
        "payment_para": "The SERVICE PROVIDER shall invoice the CLIENT after each completed event, attaching the signed event completion report, and the CLIENT shall pay each undisputed invoice within thirty (30) calendar days from receipt of a valid invoice and official receipt.",
        "payment_more": [
            "The CLIENT may withhold payment of any disputed portion of an invoice by giving written notice of the dispute within ten (10) Business Days after receipt of the invoice. The undisputed portion shall be paid when due.",
            "All payments shall be subject to the withholding taxes required under the National Internal Revenue Code, and the CLIENT shall issue the corresponding BIR Form 2307 for amounts withheld.",
            "No advance payment or mobilization fee shall be due under this Agreement.",
        ],
        "security_heading": "PERFORMANCE SECURITY",
        "security_para": "Within ten (10) days from signing of this Agreement, the SERVICE PROVIDER shall post a performance security in the form of a surety bond or irrevocable letter of credit in favor of the CLIENT in the amount of ONE MILLION TWO HUNDRED FORTY-EIGHT THOUSAND PESOS (PHP 1,248,000.00), equivalent to ten percent (10%) of the Contract Price, valid until sixty (60) days after the end of the Term.",
        "penalty_para": "For every day of delay in the arrival of the full crew complement at the call time stated in the crew call sheet, or for any event at which the SERVICE PROVIDER fails to deploy the full crew complement, the SERVICE PROVIDER shall pay the CLIENT liquidated damages equivalent to one-tenth of one percent (0.1%) of the Contract Price per day of delay, provided that the total liquidated damages shall not exceed ten percent (10%) of the Contract Price.",
        "penalty_more": [
            "In addition, any confirmed safety violation by the SERVICE PROVIDER's Personnel that results in a stop-work order at a venue shall entitle the CLIENT to a service credit of five percent (5%) of the fees for that event.",
            "Liquidated damages and service credits may be deducted from any amount due to the SERVICE PROVIDER or charged against the performance security.",
        ],
        "termination_para": "Either party may terminate this Agreement for a material breach by the other party that remains uncured fifteen (15) days after receipt of written notice. The CLIENT may also terminate this Agreement for convenience by giving the SERVICE PROVIDER at least thirty (30) days prior written notice, in which case the CLIENT shall pay only for services actually rendered up to the effective date of termination.",
        "insurance_amounts": ("TWENTY-FIVE MILLION PESOS (PHP 25,000,000.00)", "TEN MILLION PESOS (PHP 10,000,000.00)"),
        "liability_cap": "the fees paid to the SERVICE PROVIDER during the six (6) months immediately preceding the event giving rise to the claim",
        "vendor_obligations": [
            "Recruit, train, supervise, and pay its Personnel, and remit all mandatory contributions to the SSS, PhilHealth, and Pag-IBIG Fund.",
            "Comply with the Occupational Safety and Health Standards under Republic Act No. 11058 and the house rules of every venue.",
            "Replace, within four (4) hours of a request by the CLIENT, any Personnel found to be unfit, intoxicated, or insubordinate.",
            "Maintain accurate attendance records and make them available to the CLIENT for audit.",
            "Keep all CLIENT equipment entrusted to its Personnel in safe custody and report any loss or damage immediately.",
        ],
        "annex_a_title": "ANNEX A - CREW ROLES AND MINIMUM QUALIFICATIONS",
        "annex_a": [
            ["Role", "Minimum Qualification"],
            ["Crew Chief", "5 years live-event experience; first-aid certified"],
            ["Stage Manager", "3 years show-calling experience"],
            ["Rigger", "Rigging and working-at-height certification"],
            ["Lighting Technician", "Console operation (grandMA or equivalent)"],
            ["Sound Technician", "FOH/monitor mixing on digital consoles"],
            ["Backstage Crew", "Basic safety orientation; 18 years or older"],
        ],
        "annex_b_title": "ANNEX B - SCHEDULE OF RATES",
        "annex_b": [
            ["Item", "Unit", "Rate", "Est. Qty", "Amount"],
            ["Full crew package (14 pax)", "Event day", "PHP 240,000.00", "48", "PHP 11,520,000.00"],
            ["Additional technician", "Person-day", "PHP 4,000.00", "120", "PHP 480,000.00"],
            ["Overtime (crew package)", "Hour", "PHP 20,000.00", "24", "PHP 480,000.00"],
            ["Total", "", "", "", "PHP 12,480,000.00"],
        ],
        "expected": {
            "payment_terms": "30 Days",
            "renewal_terms": "Auto-renew (12 months)",
        },
    },
    {
        "slug": "long-supply-01-av-lighting-staging-equipment",
        "code": "JBC-SUP-2026-0208",
        "title": "Supply and Delivery Agreement for Audio-Visual, Lighting and Staging Equipment",
        "short": "Supply Agreement - AV, Lighting and Staging",
        "contract_type": "Supply Agreement",
        "C": "BUYER",
        "V": "SUPPLIER",
        "vendor": "LumenStage AV Trading Corp.",
        "vendor_addr": "Unit 5, Northgate Cyberzone, Filinvest City, Alabang, Muntinlupa City, Philippines",
        "vendor_sec": "CS201804116",
        "vendor_sec_date": "February 9, 2018",
        "vendor_business": "the importation, distribution, installation, and servicing of professional audio-visual, lighting, and staging equipment",
        "client_need": "to acquire its own inventory of professional audio-visual, lighting, and staging equipment in order to reduce rental costs and improve control over the quality of its productions",
        "canvass_date": "July 6, 2026",
        "proposal_date": "July 20, 2026",
        "board_date": "August 13, 2026",
        "vendor_board_date": "August 20, 2026",
        "signed_ordinal": "28th day of August 2026",
        "signed_iso": "2026-08-28",
        "signed_place": "Pasig City",
        "start_text": "September 15, 2026",
        "end_text": "March 31, 2027",
        "start_iso": "2026-09-15",
        "end_iso": "2027-03-31",
        "term_words": "approximately six and one-half (6.5) months",
        "value": 9_875_500.00,
        "value_basis": "inclusive of delivery, installation, testing, commissioning, operator training, and twelve percent (12%) value-added tax",
        "client_sig": ("Patricia J. Bernal", "Managing Director"),
        "vendor_sig": ("Isabelle G. Tan", "Vice President for Sales"),
        "witnesses": ("Carlo M. Reyes", "Miguel R. Bautista"),
        "notary_city": "Pasig City",
        "scope_heading": "SCOPE OF SUPPLY",
        "scope": [
            "The SUPPLIER shall supply, deliver, install, test, and commission at the BUYER's Pasig warehouse the professional audio-visual, lighting, and staging equipment listed in Annex A (the \"Goods\"), brand-new and in original packaging, together with operator training for up to ten (10) BUYER personnel and all manuals, software licenses, and warranty certificates.",
            "Delivery shall be made in three (3) batches in accordance with the delivery schedule in Annex B. Each batch shall be accompanied by a delivery receipt, packing list, serial-number inventory, and certificate of origin. Title to and risk of loss of the Goods shall pass to the BUYER only upon signed acceptance of each batch.",
            "Within five (5) Business Days after delivery of each batch, the parties shall jointly conduct acceptance testing. Goods that fail testing or do not conform to the specifications in Annex A shall be replaced by the SUPPLIER at its own cost within fifteen (15) days.",
            "The SUPPLIER shall conduct at least eighteen (18) hours of hands-on operator training on the lighting console, digital audio mixer, and LED wall processor, and shall provide a training completion certificate for each participant.",
        ],
        "term_para": "This Agreement shall be effective from September 15, 2026 to March 31, 2027, inclusive (the \"Term\"), which period covers the delivery of all batches, acceptance testing, and operator training. The warranty obligations in Article XI shall survive the expiration of the Term.",
        "renewal_para": "This Agreement shall not be renewed or extended. Any additional requirement of the BUYER for goods or services beyond those listed in Annex A shall be the subject of a separate procurement and a new written agreement.",
        "price_heading": "CONTRACT PRICE",
        "payment_para": "The Contract Price shall be paid in three (3) milestones: thirty percent (30%) upon delivery and acceptance of Batch 1, sixty percent (60%) upon delivery and acceptance of Batches 2 and 3, and the remaining ten percent (10%) as retention money upon completion of operator training and issuance of the final certificate of acceptance.",
        "payment_more": [
            "Each milestone payment shall be released within fifteen (15) Business Days after receipt of the SUPPLIER's billing, the signed acceptance report, and the official receipt.",
            "In lieu of the retention money, the SUPPLIER may submit a special bank guarantee of equal amount, valid until the end of the warranty period.",
            "No payment shall be made for Goods that have not been delivered and accepted.",
        ],
        "security_heading": "PERFORMANCE SECURITY",
        "security_para": "Within five (5) days from signing of this Agreement, the SUPPLIER shall post a performance security in the amount of NINE HUNDRED EIGHTY-SEVEN THOUSAND FIVE HUNDRED FIFTY PESOS (PHP 987,550.00), equivalent to ten percent (10%) of the Contract Price, in the form of a callable surety bond issued by an insurance company accredited by the Insurance Commission.",
        "penalty_para": "Should the SUPPLIER fail to deliver any batch on the date stated in Annex B, the SUPPLIER shall pay the BUYER liquidated damages equivalent to one percent (1%) of the value of the undelivered Goods for each day of delay, up to a maximum of ten percent (10%) of the Contract Price, after which the BUYER may terminate this Agreement and forfeit the performance security.",
        "penalty_more": [
            "Goods that remain non-conforming after the fifteen-day replacement period shall be deducted from the Contract Price at their unit price in Annex A, without prejudice to the BUYER's other remedies.",
            "Liquidated damages may be deducted from any payment due to the SUPPLIER.",
        ],
        "termination_para": "The BUYER may terminate this Agreement by written notice if the SUPPLIER fails to deliver any batch within thirty (30) days after its scheduled delivery date, or commits any other material breach that remains uncured fifteen (15) days after written notice. Either party may terminate this Agreement upon the insolvency, receivership, or cessation of business of the other party.",
        "insurance_amounts": ("TEN MILLION PESOS (PHP 10,000,000.00)", "THREE MILLION PESOS (PHP 3,000,000.00)"),
        "liability_cap": "the Contract Price",
        "vendor_obligations": [
            "Deliver Goods that are brand-new, of merchantable quality, free from defects in design, materials, and workmanship, and fully compliant with Annex A.",
            "Secure all import permits and pay all customs duties, taxes, and charges up to delivery at the BUYER's warehouse.",
            "Provide a warranty of not less than twelve (12) months from final acceptance, with on-site response within forty-eight (48) hours for reported defects.",
            "Maintain a local stock of critical spare parts for at least five (5) years after final acceptance.",
            "Remove all packaging materials and debris from the BUYER's premises after installation.",
        ],
        "annex_a_title": "ANNEX A - TECHNICAL SPECIFICATIONS AND BILL OF QUANTITIES",
        "annex_a": [
            ["Item", "Description", "Qty", "Unit Price", "Amount"],
            ["1", "Digital audio mixer, 48-channel, with stage box", "2", "PHP 685,000.00", "PHP 1,370,000.00"],
            ["2", "Line-array speaker system (per side, 8 boxes)", "2", "PHP 1,450,000.00", "PHP 2,900,000.00"],
            ["3", "Moving-head wash luminaire, LED", "24", "PHP 72,500.00", "PHP 1,740,000.00"],
            ["4", "Lighting console with wing", "1", "PHP 895,500.00", "PHP 895,500.00"],
            ["5", "Indoor LED wall, P2.9, 24 sq. m., with processor", "1", "PHP 2,150,000.00", "PHP 2,150,000.00"],
            ["6", "Modular staging decks, 1.22m x 2.44m", "40", "PHP 20,500.00", "PHP 820,000.00"],
            ["", "Total (VAT inclusive)", "", "", "PHP 9,875,500.00"],
        ],
        "annex_b_title": "ANNEX B - DELIVERY SCHEDULE",
        "annex_b": [
            ["Batch", "Items", "Delivery Date"],
            ["Batch 1", "Items 1 and 2 (audio)", "October 15, 2026"],
            ["Batch 2", "Items 3 and 4 (lighting)", "November 30, 2026"],
            ["Batch 3", "Items 5 and 6 (LED wall and staging)", "January 29, 2027"],
            ["Training", "Operator training, 18 hours", "February 2027"],
        ],
        "expected": {
            "payment_terms": "Milestone-based",
            "renewal_terms": "No renewal",
        },
    },
    {
        "slug": "long-service-02-security-crowd-control",
        "code": "JBC-SEC-2026-0089",
        "title": "Security and Crowd Control Services Agreement",
        "short": "Security and Crowd Control Services",
        "contract_type": "Service Agreement",
        "C": "CLIENT",
        "V": "CONTRACTOR",
        "vendor": "Nightwatch Event Security Inc.",
        "vendor_addr": "88 Katipunan Avenue, Loyola Heights, Quezon City, Metro Manila, Philippines",
        "vendor_sec": "CS200907731",
        "vendor_sec_date": "June 30, 2009",
        "vendor_business": "the provision of licensed security guards, crowd-control marshals, and event security planning, and holds a valid License to Operate issued by the PNP Supervisory Office for Security and Investigation Agencies",
        "client_need": "licensed security guards and trained crowd-control marshals for its warehouse, its corporate office, and the concerts, festivals, and corporate events it organizes",
        "canvass_date": "March 16, 2026",
        "proposal_date": "March 30, 2026",
        "board_date": "May 7, 2026",
        "vendor_board_date": "May 14, 2026",
        "signed_ordinal": "25th day of May 2026",
        "signed_iso": "2026-05-25",
        "signed_place": "Quezon City",
        "start_text": "June 1, 2026",
        "end_text": "May 31, 2027",
        "start_iso": "2026-06-01",
        "end_iso": "2027-05-31",
        "term_words": "one (1) year",
        "value": 4_320_000.00,
        "value_basis": "computed at a fixed monthly fee of THREE HUNDRED SIXTY THOUSAND PESOS (PHP 360,000.00) for twelve (12) months",
        "client_sig": ("Nina S. Domingo", "Procurement Director"),
        "vendor_sig": ("Ramon T. Aquino", "President"),
        "witnesses": ("Patricia J. Bernal", "Liza M. Navarro"),
        "notary_city": "Quezon City",
        "scope_heading": "SCOPE OF SERVICES",
        "scope": [
            "The CONTRACTOR shall provide licensed, uniformed, and armed or unarmed security guards, as specified in Annex A, for twenty-four (24) hour protection of the CLIENT's corporate office and warehouse, and trained crowd-control marshals, bag-check personnel, and VIP lane officers for events organized by the CLIENT, together with post-event incident reports.",
            "Fixed posts shall be manned continuously on twelve (12) hour shifts. Event deployments shall be requested by the CLIENT through a deployment order issued at least seventy-two (72) hours before the event, stating the venue, expected attendance, number of marshals, and call time.",
            "The CONTRACTOR shall assign a detachment commander who shall conduct daily inspections, maintain the logbook, and coordinate with the CLIENT's administrative officer and with venue security, the local police, and emergency medical services during events.",
            "The CONTRACTOR shall submit a monthly security assessment report identifying risks, incidents, and recommended improvements for the CLIENT's premises.",
        ],
        "term_para": "The term of this Agreement shall commence on June 1, 2026 and shall expire on May 31, 2027 (the \"Term\"), unless sooner terminated in accordance with Article XVIII or renewed in accordance with Article IV.",
        "renewal_para": "This Agreement shall be automatically renewed for successive periods of six (6) months each, on the same terms and conditions, unless either party notifies the other in writing of its intention not to renew at least thirty (30) days before the expiration of the then-current Term.",
        "price_heading": "CONTRACT PRICE",
        "payment_para": "The CONTRACTOR shall bill the CLIENT monthly in arrears for the fixed monthly fee and any approved event deployments for the preceding month, and the CLIENT shall pay each correct billing on or before the last Business Day of the month in which it is received.",
        "payment_more": [
            "Each billing shall be supported by duty detail orders, daily time records, and the signed deployment orders for events.",
            "Any increase in the statutory minimum wage or mandatory contributions during the Term shall be passed on to the CLIENT only to the extent required by law and upon presentation of supporting computations.",
            "The CLIENT shall deduct from each billing the applicable withholding taxes and any penalties due under Article X.",
        ],
        "security_heading": "PERFORMANCE SECURITY AND BOND",
        "security_para": "The CONTRACTOR shall post, within ten (10) days from signing, a fidelity bond of not less than TWO MILLION PESOS (PHP 2,000,000.00) covering loss of CLIENT property through the acts of its guards, and a performance bond of FOUR HUNDRED THIRTY-TWO THOUSAND PESOS (PHP 432,000.00), both valid for the entire Term.",
        "penalty_para": "For every post left unmanned, or every marshal not deployed as ordered, the CONTRACTOR shall pay a penalty equivalent to two percent (2%) of the monthly fee for each post per day, which the CLIENT may deduct from the next billing.",
        "penalty_more": [
            "Any guard found sleeping, absent from post, or under the influence of alcohol while on duty shall be replaced immediately, and the CONTRACTOR shall be charged a penalty of FIVE THOUSAND PESOS (PHP 5,000.00) per incident.",
            "The CONTRACTOR shall be liable for the full replacement value of any CLIENT property lost through the negligence or fault of its guards, as determined after joint investigation.",
        ],
        "termination_para": "Either party may terminate this Agreement with thirty (30) days prior written notice for material breach not cured within fifteen (15) days from receipt of notice. The CLIENT may terminate immediately upon revocation or suspension of the CONTRACTOR's License to Operate.",
        "insurance_amounts": ("FIFTEEN MILLION PESOS (PHP 15,000,000.00)", "FIVE MILLION PESOS (PHP 5,000,000.00)"),
        "liability_cap": "the fees paid to the CONTRACTOR during the twelve (12) months immediately preceding the event giving rise to the claim",
        "vendor_obligations": [
            "Deploy only guards who are duly licensed by the PNP-SOSIA, have passed neuro-psychiatric and drug tests within the last twelve (12) months, and have completed basic security training.",
            "Pay its guards not less than the applicable minimum wage and all benefits required under the Labor Code and PADPAO standards.",
            "Provide firearms, ammunition, radios, batons, flashlights, and uniforms, all properly licensed and in good condition.",
            "Rotate guards assigned to fixed posts at least every six (6) months, or earlier upon request of the CLIENT.",
            "Submit incident reports within twenty-four (24) hours after any security incident.",
        ],
        "annex_a_title": "ANNEX A - POSTS AND DEPLOYMENT",
        "annex_a": [
            ["Post", "Location", "Shift", "Guards"],
            ["Post 1", "Corporate office lobby, Ortigas Center", "24 hrs (2 x 12)", "2 unarmed"],
            ["Post 2", "Warehouse main gate, Taguig City", "24 hrs (2 x 12)", "2 armed"],
            ["Post 3", "Warehouse roving", "Night shift only", "1 armed"],
            ["Events", "Venue as stated in deployment order", "Per event", "As ordered"],
        ],
        "annex_b_title": "ANNEX B - FEES",
        "annex_b": [
            ["Item", "Basis", "Amount"],
            ["Fixed posts (Posts 1-3)", "Monthly", "PHP 285,000.00"],
            ["Event marshal allowance", "Monthly, up to 40 marshal-days", "PHP 75,000.00"],
            ["Monthly fee", "", "PHP 360,000.00"],
            ["Contract Price (12 months)", "", "PHP 4,320,000.00"],
        ],
        "expected": {
            "payment_terms": "Monthly",
            "renewal_terms": "Auto-renew (6 months)",
        },
    },
]


# --------------------------------------------------------------------------
# Document body (list of blocks shared by the PDF and TXT renderers)
# --------------------------------------------------------------------------

def build_blocks(c: dict) -> list[tuple]:
    C, V = c["C"], c["V"]
    vendor, client = c["vendor"], CLIENT
    title = c["title"]
    blocks: list[tuple] = []
    add = blocks.append

    # Cover page
    add(("spacer", 90))
    add(("center_big", "REPUBLIC OF THE PHILIPPINES"))
    add(("spacer", 30))
    add(("title", title.upper()))
    add(("spacer", 24))
    add(("center", "by and between"))
    add(("spacer", 10))
    add(("center_bold", client))
    add(("center", f"(as {C})"))
    add(("spacer", 8))
    add(("center", "and"))
    add(("spacer", 8))
    add(("center_bold", vendor))
    add(("center", f"(as {V})"))
    add(("spacer", 40))
    add(("center", f"Contract Reference No. {c['code']}"))
    add(("spacer", 60))
    add(("center_small", "CONFIDENTIAL. This document contains proprietary commercial terms. Unauthorized reproduction, distribution, or disclosure is strictly prohibited."))
    add(("pagebreak",))

    # Table of contents (headings only)
    articles = article_list(c)
    add(("heading_center", "TABLE OF CONTENTS"))
    toc_rows = [["Article", "Heading"]] + [[ROMAN[i], h] for i, (h, _) in enumerate(articles)]
    toc_rows += [["", "Signatures and Acknowledgment"], ["", c["annex_a_title"].title()], ["", c["annex_b_title"].title()]]
    add(("table", toc_rows, [0.8 * inch, 5.2 * inch]))
    add(("pagebreak",))

    # Preamble
    add(("title", title.upper()))
    add(("spacer", 8))
    add(("para_bold", "KNOW ALL MEN BY THESE PRESENTS:"))
    add(("para", f"This {title} (the \"Agreement\") is made and entered into this {c['signed_ordinal']} at {c['signed_place']}, Philippines, by and between:"))
    add(("para", f"<b>{client}</b>, a corporation duly organized and existing under and by virtue of the laws of the Republic of the Philippines, with principal office at {CLIENT_ADDR}, represented herein by its {c['client_sig'][1]}, {c['client_sig'][0].upper()}, who is duly authorized for this purpose as evidenced by the Secretary's Certificate attached hereto as Annex C, hereinafter referred to as the \"{C}\";"))
    add(("center", "- and -"))
    add(("para", f"<b>{vendor}</b>, a corporation duly organized and existing under and by virtue of the laws of the Republic of the Philippines, with principal office at {c['vendor_addr']}, represented herein by its {c['vendor_sig'][1]}, {c['vendor_sig'][0].upper()}, who is duly authorized for this purpose as evidenced by the Secretary's Certificate attached hereto as Annex D, hereinafter referred to as the \"{V}\"."))
    add(("para", f"The {C} and the {V} are hereinafter referred to individually as a \"Party\" and collectively as the \"Parties\"."))

    add(("heading_center", "WITNESSETH:"))
    whereas = [
        f"the {C} is engaged in the business of event planning, management, and production, and organizes more than one hundred fifty (150) corporate, social, and public events every year in Metro Manila and nearby provinces;",
        f"the {C} requires {c['client_need']};",
        f"the {V} is a corporation duly registered with the Securities and Exchange Commission under Company Registration No. {c['vendor_sec']} issued on {c['vendor_sec_date']}, and is engaged in {c['vendor_business']};",
        f"the {V} represents that it possesses the technical capability, financial capacity, qualified personnel, licenses, and permits necessary to perform its obligations under this Agreement, including a valid Mayor's Permit and BIR Certificate of Registration;",
        f"on {c['canvass_date']}, the {C} conducted a competitive canvass among no fewer than three (3) qualified suppliers, and the {V} submitted the lowest calculated and most responsive proposal dated {c['proposal_date']};",
        f"the Board of Directors of the {C}, in a resolution adopted on {c['board_date']}, approved the award of this engagement to the {V} and authorized its representative to sign this Agreement;",
        f"the Board of Directors of the {V}, in a resolution adopted on {c['vendor_board_date']}, likewise authorized its representative to sign this Agreement;",
        f"the {V} has examined the requirements of the {C}, has satisfied itself as to the conditions affecting the work, and confirms that the price stated herein is sufficient to cover all its obligations;",
        "both Parties have conducted their own due diligence and confirm that no conflict of interest exists between them, their officers, and their employees with respect to this engagement;",
        "the Parties desire to set forth in writing the terms and conditions governing their relationship;",
    ]
    for item in whereas:
        add(("para", f"<b>WHEREAS</b>, {item}"))
    add(("para", "<b>NOW, THEREFORE</b>, for and in consideration of the foregoing premises and the mutual covenants and undertakings hereinafter set forth, the Parties hereby agree as follows:"))

    for idx, (heading, paras) in enumerate(articles):
        num = idx + 1
        add(("heading", f"ARTICLE {ROMAN[idx]}"))
        add(("heading_sub", heading))
        for sub, text in enumerate(paras, start=1):
            if isinstance(text, list):
                for item in text:
                    add(("bullet", item))
            else:
                add(("para", f"{num}.{sub} {text}"))

    # Signatures
    add(("para", f"<b>IN WITNESS WHEREOF</b>, the Parties have hereunto set their hands on the date and at the place first above written."))
    add(("spacer", 6))
    for role, company, (name, position) in ((C, client, c["client_sig"]), (V, vendor, c["vendor_sig"])):
        add(("sig", [f"For the {role}:", company.upper(), "", "By:  ______________________________", name.upper(), position]))
    add(("para_bold", "SIGNED IN THE PRESENCE OF:"))
    add(("sig", ["______________________________          ______________________________",
                 f"{c['witnesses'][0]:<42}{c['witnesses'][1]}"]))

    # Notarial acknowledgment
    add(("pagebreak",))
    add(("heading_center", "ACKNOWLEDGMENT"))
    add(("para", f"REPUBLIC OF THE PHILIPPINES )<br/>{c['notary_city'].upper()} ) S.S."))
    add(("para", f"BEFORE ME, a Notary Public for and in {c['notary_city']}, personally appeared the following persons, exhibiting to me their competent evidence of identity as indicated below:"))
    add(("table", [
        ["Name", "Competent Evidence of Identity", "Date / Place Issued"],
        [c["client_sig"][0], "Passport No. P4471209B", "11 January 2024 / DFA Manila"],
        [c["vendor_sig"][0], "Driver's License No. N02-15-338471", "02 March 2025 / LTO Quezon City"],
    ], [2.0 * inch, 2.3 * inch, 2.0 * inch]))
    add(("para", f"known to me and to me known to be the same persons who executed the foregoing {title}, consisting of the pages hereof including the page on which this Acknowledgment is written, and who acknowledged to me that the same is their free and voluntary act and deed as well as that of the corporations they respectively represent."))
    add(("para", "WITNESS MY HAND AND SEAL on the date and at the place first above written."))
    add(("sig", ["Doc. No. ____;", "Page No. ____;", "Book No. ____;", "Series of 2026.", "", "NOTARY PUBLIC", "Until December 31, 2027", "Roll of Attorneys No. 71234"]))

    # Annexes
    add(("pagebreak",))
    add(("heading_center", c["annex_a_title"]))
    add(("table", c["annex_a"], None))
    add(("spacer", 18))
    add(("heading_center", c["annex_b_title"]))
    add(("table", c["annex_b"], None))
    add(("spacer", 12))
    add(("para_small", "Annexes C and D (Secretary's Certificates) are on file with the Parties and are incorporated herein by reference."))
    return blocks


def article_list(c: dict) -> list[tuple[str, list]]:
    C, V = c["C"], c["V"]
    title = c["title"]
    ins_main, ins_second = c["insurance_amounts"]
    return [
        ("DEFINITIONS AND INTERPRETATION", [
            "In this Agreement, unless the context otherwise requires, the following words and expressions shall have the meanings hereby assigned to them:",
            [
                f"\"Agreement\" means this {title}, including its Annexes, as may be amended from time to time in accordance with its terms.",
                "\"Applicable Law\" means all statutes, ordinances, regulations, orders, and issuances of any governmental authority of the Republic of the Philippines that apply to a Party or to the subject matter of this Agreement.",
                "\"Business Day\" means any day other than a Saturday, Sunday, or regular or special non-working holiday declared in Metro Manila.",
                "\"Confidential Information\" means all information disclosed by one Party to the other, whether orally, in writing, or electronically, that is marked as confidential or that a reasonable person would understand to be confidential, including client lists, pricing, event plans, and trade secrets.",
                "\"Contract Price\" means the total amount payable by the " + C + " to the " + V + " under Article V.",
                "\"Effective Date\" means the date on which the Term commences, as stated in Article III.",
                "\"Force Majeure Event\" has the meaning given in Article XVII.",
                "\"Intellectual Property Rights\" means patents, copyrights, trademarks, trade names, designs, know-how, and all similar rights, whether registered or not.",
                "\"Personal Data\" has the meaning given to it under Republic Act No. 10173, otherwise known as the Data Privacy Act of 2012, and its Implementing Rules and Regulations.",
                "\"Personnel\" means the officers, employees, agents, and subcontractors of a Party who are engaged in the performance of this Agreement.",
                "\"Term\" means the period described in Article III, as may be renewed or extended in accordance with Article IV.",
            ],
            "Headings are inserted for convenience only and shall not affect the interpretation of this Agreement. Words importing the singular include the plural and vice versa. The words \"include\" and \"including\" shall be read without limitation. References to a statute include that statute as amended or replaced.",
            f"In case of conflict or inconsistency among the documents forming part of this Agreement, the following order of precedence shall apply: (a) this Agreement; (b) Annex A; (c) Annex B; and (d) the proposal of the {V} dated {c['proposal_date']}, to the extent incorporated herein.",
        ]),
        (c["scope_heading"], c["scope"]),
        ("TERM OF THE AGREEMENT", [
            c["term_para"],
            f"Obligations which by their nature are intended to survive the expiration or termination of this Agreement, including those relating to confidentiality, data privacy, indemnification, warranties, and payment of amounts already due, shall survive for the period stated in the relevant Article or, if none is stated, for the period allowed under Applicable Law.",
        ]),
        ("RENEWAL", [
            c["renewal_para"],
            "Any renewal or extension shall be documented in a written instrument signed by the authorized representatives of both Parties, and no course of dealing or acceptance of payment after expiration shall be construed as a renewal unless expressly provided in this Article.",
        ]),
        (c["price_heading"], [
            f"In consideration of the faithful performance by the {V} of its obligations under this Agreement, the {C} shall pay the {V} a total contract price of {php_long(c['value'])}, {c['value_basis']} (the \"Contract Price\").",
            f"The Contract Price is fixed and shall not be subject to escalation during the Term, except as expressly provided herein or as required by Applicable Law. The Contract Price includes all costs of labor, materials, equipment, transportation, overhead, profit, and taxes, other than taxes that are by law for the account of the {C}.",
            f"The {V} acknowledges that it has satisfied itself as to the sufficiency of the Contract Price and shall not be entitled to any additional payment by reason of any error or omission in its own estimates.",
        ]),
        ("PAYMENT TERMS", [c["payment_para"]] + c["payment_more"]),
        (c["security_heading"], [
            c["security_para"],
            f"The {C} may draw on the security to recover any amount due from the {V} under this Agreement, including liquidated damages, penalties, and the cost of remedying any default, without prejudice to any other remedy available to the {C}.",
        ]),
        (f"OBLIGATIONS OF THE {V}", [
            f"In addition to its other obligations under this Agreement, the {V} shall:",
            c["vendor_obligations"],
            f"The {V} shall perform its obligations with the degree of skill, care, and diligence reasonably expected of an experienced and reputable provider of similar goods or services in the Philippines.",
        ]),
        (f"OBLIGATIONS OF THE {C}", [
            f"The {C} shall pay the Contract Price in accordance with Article VI, provide the {V} with timely access to its premises and venues as reasonably necessary, and designate a representative who shall coordinate with the {V} on all matters relating to this Agreement.",
            f"The {C} shall give the {V} reasonable advance notice of any change in its schedule, venue, or requirements that may affect the performance of this Agreement, and the Parties shall agree in writing on any resulting adjustment.",
        ]),
        ("PENALTIES AND LIQUIDATED DAMAGES", [c["penalty_para"]] + c["penalty_more"] + [
            "The Parties agree that the liquidated damages and penalties provided in this Article are a genuine pre-estimate of the loss likely to be suffered and are not a penalty in the sense of Article 1229 of the Civil Code of the Philippines.",
        ]),
        ("WARRANTIES AND REPRESENTATIONS", [
            "Each Party represents and warrants that: (a) it is duly organized, validly existing, and in good standing under the laws of the Philippines; (b) it has full corporate power and authority to enter into and perform this Agreement; (c) the execution of this Agreement does not violate its articles of incorporation, by-laws, or any agreement to which it is a party; and (d) there is no pending or threatened litigation that would materially affect its ability to perform this Agreement.",
            f"The {V} further warrants that all goods, premises, or services furnished under this Agreement shall conform to the specifications in the Annexes, shall be fit for the purpose for which they are intended, and shall comply with all Applicable Law, including laws on labor, safety, health, and the environment.",
            f"The {V} warrants that it has not given or promised any commission, gift, or other benefit to any officer or employee of the {C} in connection with the award or performance of this Agreement.",
        ]),
        ("INSURANCE", [
            f"The {V} shall, at its own cost, obtain and maintain throughout the Term comprehensive general liability insurance with a limit of not less than {ins_main} per occurrence, and such other insurance as may be required by Applicable Law, naming the {C} as an additional insured.",
            f"The {V} shall likewise maintain property or all-risk insurance in an amount of not less than {ins_second}, and shall deliver certificates of insurance to the {C} within fifteen (15) days from signing and upon each renewal of the policies.",
            f"Failure to maintain the required insurance shall entitle the {C} to procure the same and deduct the premiums from any amount due to the {V}.",
        ]),
        ("CONFIDENTIALITY", [
            "Each Party shall hold the Confidential Information of the other Party in strict confidence, shall use it only for the purposes of this Agreement, and shall not disclose it to any third party without the prior written consent of the disclosing Party, except to its Personnel and professional advisers who need to know it and are bound by equivalent obligations.",
            "The obligations in this Article shall not apply to information that: (a) is or becomes publicly available through no fault of the receiving Party; (b) was lawfully known to the receiving Party before disclosure; (c) is independently developed without use of the Confidential Information; or (d) is required to be disclosed by law or by order of a competent court, provided that prompt notice is given to the disclosing Party.",
            "The obligations of confidentiality shall survive for a period of three (3) years after the expiration or termination of this Agreement.",
        ]),
        ("DATA PRIVACY", [
            f"To the extent that the {V} processes Personal Data on behalf of the {C}, the {V} shall act as a personal information processor and shall process such Personal Data only on the documented instructions of the {C} and in compliance with the Data Privacy Act of 2012, its Implementing Rules and Regulations, and the issuances of the National Privacy Commission.",
            f"The {V} shall implement reasonable and appropriate organizational, physical, and technical security measures to protect Personal Data, shall notify the {C} of any personal data breach within twenty-four (24) hours from discovery, and shall return or securely destroy all Personal Data upon termination of this Agreement.",
        ]),
        ("INDEMNIFICATION", [
            f"The {V} shall defend, indemnify, and hold harmless the {C}, its directors, officers, employees, and clients from and against all claims, losses, damages, liabilities, fines, costs, and expenses, including reasonable attorney's fees, arising from: (a) any breach of this Agreement by the {V}; (b) the negligence or willful misconduct of the {V} or its Personnel; (c) any claim by the {V}'s Personnel for wages, benefits, or injuries; and (d) any infringement of Intellectual Property Rights by goods or materials supplied by the {V}.",
            "The indemnified Party shall give prompt written notice of any claim, allow the indemnifying Party to control the defense, and provide reasonable assistance at the indemnifying Party's expense.",
        ]),
        ("LIMITATION OF LIABILITY", [
            f"Except for liability arising from fraud, gross negligence, willful misconduct, breach of confidentiality, data privacy violations, or the indemnities in Article XV, the aggregate liability of each Party under this Agreement shall not exceed {c['liability_cap']}.",
            "Neither Party shall be liable to the other for indirect, special, or consequential damages, including loss of profit, revenue, or goodwill, even if advised of the possibility of such damages.",
        ]),
        ("FORCE MAJEURE", [
            "Neither Party shall be liable for any delay or failure to perform its obligations, other than obligations to pay money, to the extent caused by a Force Majeure Event, meaning an event beyond the reasonable control of the affected Party, including typhoons, earthquakes, floods, fire, epidemics, war, civil disturbance, and acts of government, that could not have been prevented by reasonable diligence.",
            "The affected Party shall notify the other Party in writing within three (3) Business Days after the occurrence of the Force Majeure Event, shall use reasonable efforts to mitigate its effects, and shall resume performance as soon as practicable. If a Force Majeure Event continues for more than sixty (60) consecutive days, either Party may terminate this Agreement by written notice without liability.",
        ]),
        ("TERMINATION", [
            c["termination_para"],
            "Either Party may terminate this Agreement immediately by written notice if the other Party becomes insolvent, makes an assignment for the benefit of creditors, is placed under receivership or rehabilitation, or ceases to carry on business in the ordinary course.",
            "Termination shall be effected by written notice delivered in accordance with Article XXII, stating the grounds for termination and the effective date thereof.",
        ]),
        ("EFFECTS OF TERMINATION", [
            f"Upon expiration or termination of this Agreement for any reason, the {V} shall promptly return all property, documents, and Confidential Information of the {C} in its possession, and the {C} shall pay all undisputed amounts due for obligations performed up to the effective date of termination.",
            "Termination shall be without prejudice to any rights or remedies accrued to either Party before the effective date of termination, including the right to claim damages for any breach.",
        ]),
        ("DISPUTE RESOLUTION", [
            "The Parties shall endeavor to settle amicably any dispute, controversy, or claim arising out of or relating to this Agreement through good-faith negotiations between their senior officers within thirty (30) days from written notice of the dispute.",
            "Any dispute not settled by negotiation shall be referred to mediation under the rules of the Philippine Mediation Center, and failing settlement within sixty (60) days, to arbitration in Metro Manila in accordance with Republic Act No. 9285, the Alternative Dispute Resolution Act of 2004. The arbitral award shall be final and binding on the Parties.",
        ]),
        ("GOVERNING LAW AND VENUE", [
            "This Agreement shall be governed by and construed in accordance with the laws of the Republic of the Philippines.",
            "Subject to Article XX, any action to enforce an arbitral award or to obtain interim relief shall be filed exclusively in the proper courts of Pasig City, to the exclusion of all other venues.",
        ]),
        ("NOTICES", [
            "All notices under this Agreement shall be in writing and delivered personally, by registered mail, by reputable courier, or by electronic mail with confirmation of receipt, to the addresses below or to such other address as a Party may designate by written notice:",
            [
                f"To the {C}: {CLIENT}, {CLIENT_ADDR}, Attention: {c['client_sig'][0]}, {c['client_sig'][1]}; e-mail: legal@jbcevents.example.ph",
                f"To the {V}: {c['vendor']}, {c['vendor_addr']}, Attention: {c['vendor_sig'][0]}, {c['vendor_sig'][1]}; e-mail: contracts@{c['vendor'].split()[0].lower()}.example.ph",
            ],
            "Notices shall be deemed received upon personal delivery, three (3) Business Days after mailing by registered mail or courier, or upon confirmation of receipt in the case of electronic mail.",
        ]),
        ("ASSIGNMENT AND SUBCONTRACTING", [
            f"Neither Party shall assign or transfer this Agreement or any of its rights or obligations hereunder without the prior written consent of the other Party. The {V} shall not subcontract any part of its obligations without the prior written consent of the {C}, and shall remain fully responsible for the acts and omissions of its subcontractors.",
        ]),
        ("RELATIONSHIP OF THE PARTIES", [
            f"The {V} is an independent contractor. Nothing in this Agreement shall be construed to create a partnership, joint venture, agency, or employer-employee relationship between the {C} and the {V} or its Personnel. The {V} shall have exclusive control over the means and methods of performing its obligations and shall be solely responsible for the wages, benefits, and statutory contributions of its Personnel.",
        ]),
        ("ANTI-CORRUPTION AND ETHICAL CONDUCT", [
            "Each Party shall comply with Republic Act No. 3019, the Anti-Graft and Corrupt Practices Act, and all other applicable anti-bribery laws, and shall not offer, give, solicit, or accept any improper payment or advantage in connection with this Agreement.",
            f"The {V} shall promptly report to the {C} any request for an improper payment made by any Personnel of the {C}. Any breach of this Article shall be a material breach entitling the non-breaching Party to terminate this Agreement immediately.",
        ]),
        ("MISCELLANEOUS PROVISIONS", [
            "No amendment or modification of this Agreement shall be valid unless made in writing and signed by the authorized representatives of both Parties.",
            "The failure of a Party to insist on strict performance of any provision of this Agreement shall not be construed as a waiver of that provision or of any other provision.",
            "If any provision of this Agreement is held invalid or unenforceable, the remaining provisions shall continue in full force and effect, and the invalid provision shall be replaced by a valid provision that most closely reflects the intention of the Parties.",
            "This Agreement, together with its Annexes, constitutes the entire agreement between the Parties on its subject matter and supersedes all prior negotiations, proposals, understandings, and agreements, whether written or oral.",
            "This Agreement may be executed in several counterparts, each of which shall be deemed an original, and all of which together shall constitute one and the same instrument. Documentary stamp tax on this Agreement, if any, shall be for the account of the " + V + ".",
        ]),
    ]


# --------------------------------------------------------------------------
# Renderers
# --------------------------------------------------------------------------

class NumberedCanvas(rl_canvas.Canvas):
    """Adds 'Page X of Y', header, and initials line on every page after the cover."""

    header_text = ""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, **kwargs)
        self._saved = []

    def showPage(self):
        self._saved.append(dict(self.__dict__))
        self._startPage()

    def save(self):
        total = len(self._saved)
        for state in self._saved:
            self.__dict__.update(state)
            if self._pageNumber > 1:
                self._decorate(total)
            super().showPage()
        super().save()

    def _decorate(self, total: int) -> None:
        width, height = A4
        self.setFont("Times-Italic", 8.5)
        self.setFillColor(colors.HexColor("#444444"))
        self.drawString(inch, height - 0.6 * inch, self.header_text)
        self.drawRightString(width - inch, height - 0.6 * inch, "CONFIDENTIAL")
        self.line(inch, height - 0.66 * inch, width - inch, height - 0.66 * inch)
        self.drawString(inch, 0.55 * inch, "Initials: ________ / ________")
        self.drawRightString(width - inch, 0.55 * inch, f"Page {self._pageNumber} of {total}")


def _styles() -> dict[str, ParagraphStyle]:
    base = ParagraphStyle("base", fontName="Times-Roman", fontSize=11, leading=15, alignment=TA_JUSTIFY, spaceAfter=7)
    return {
        "para": base,
        "para_bold": ParagraphStyle("pb", parent=base, fontName="Times-Bold"),
        "para_small": ParagraphStyle("ps", parent=base, fontSize=9, leading=12),
        "bullet": ParagraphStyle("bl", parent=base, leftIndent=24, bulletIndent=10, spaceAfter=5),
        "title": ParagraphStyle("t", parent=base, fontName="Times-Bold", fontSize=15, leading=20, alignment=TA_CENTER, spaceAfter=10),
        "center": ParagraphStyle("c", parent=base, alignment=TA_CENTER),
        "center_bold": ParagraphStyle("cb", parent=base, alignment=TA_CENTER, fontName="Times-Bold", fontSize=13),
        "center_big": ParagraphStyle("cbig", parent=base, alignment=TA_CENTER, fontName="Times-Bold", fontSize=12),
        "center_small": ParagraphStyle("cs", parent=base, alignment=TA_CENTER, fontSize=9, leading=12, fontName="Times-Italic"),
        "heading": ParagraphStyle("h", parent=base, fontName="Times-Bold", alignment=TA_CENTER, spaceBefore=10, spaceAfter=0),
        "heading_sub": ParagraphStyle("hs", parent=base, fontName="Times-Bold", alignment=TA_CENTER, spaceAfter=8),
        "heading_center": ParagraphStyle("hc", parent=base, fontName="Times-Bold", alignment=TA_CENTER, fontSize=12, spaceBefore=8, spaceAfter=10),
        "sig": ParagraphStyle("sig", parent=base, alignment=0, spaceAfter=12, leading=14),
        "cell": ParagraphStyle("cell", parent=base, fontSize=9.5, leading=12, alignment=0, spaceAfter=0),
        "cell_bold": ParagraphStyle("cellb", parent=base, fontSize=9.5, leading=12, alignment=0, spaceAfter=0, fontName="Times-Bold"),
    }


def render_pdf(c: dict, blocks: list[tuple], path: Path) -> None:
    st = _styles()
    story = []
    pending_heading = None
    for block in blocks:
        kind = block[0]
        if kind == "spacer":
            story.append(Spacer(1, block[1]))
        elif kind == "pagebreak":
            story.append(PageBreak())
        elif kind == "table":
            rows, widths = block[1], block[2]
            data = [[Paragraph(str(cell), st["cell_bold"] if r == 0 else st["cell"]) for cell in row] for r, row in enumerate(rows)]
            tbl = Table(data, colWidths=widths, repeatRows=1, hAlign="CENTER")
            tbl.setStyle(TableStyle([
                ("GRID", (0, 0), (-1, -1), 0.5, colors.HexColor("#777777")),
                ("BACKGROUND", (0, 0), (-1, 0), colors.HexColor("#e8e8e8")),
                ("VALIGN", (0, 0), (-1, -1), "TOP"),
                ("TOPPADDING", (0, 0), (-1, -1), 3),
                ("BOTTOMPADDING", (0, 0), (-1, -1), 3),
            ]))
            story.append(tbl)
        elif kind == "sig":
            lines = [re.sub(r" {2,}", lambda m: "&nbsp;" * len(m.group()), line) for line in block[1]]
            story.append(KeepTogether([Paragraph("<br/>".join(lines), st["sig"])]))
        elif kind == "heading":
            pending_heading = Paragraph(block[1], st["heading"])
        elif kind == "heading_sub":
            story.append(KeepTogether([pending_heading, Paragraph(block[1], st["heading_sub"])]))
            pending_heading = None
        elif kind == "bullet":
            story.append(Paragraph(block[1], st["bullet"], bulletText="\u2022"))
        else:
            story.append(Paragraph(block[1], st[kind]))

    NumberedCanvas.header_text = f"{c['code']}  |  {c['short']}"
    doc = SimpleDocTemplate(
        str(path),
        pagesize=A4,
        leftMargin=inch,
        rightMargin=inch,
        topMargin=0.95 * inch,
        bottomMargin=0.9 * inch,
        title=c["title"],
        author=CLIENT,
        subject=c["contract_type"],
    )
    doc.build(story, canvasmaker=NumberedCanvas)


def _plain(text: str) -> str:
    return text.replace("<b>", "").replace("</b>", "").replace("<br/>", "\n")


def render_txt(blocks: list[tuple]) -> str:
    out: list[str] = []
    for block in blocks:
        kind = block[0]
        if kind in {"spacer"}:
            continue
        if kind == "pagebreak":
            out.append("")
            continue
        if kind == "table":
            for row in block[1]:
                out.append(" | ".join(str(cell) for cell in row))
            out.append("")
            continue
        if kind == "sig":
            out.extend(block[1])
            out.append("")
            continue
        if kind == "bullet":
            out.append(f"  - {_plain(block[1])}")
            out.append("")
            continue
        if kind == "heading":
            out.append(_plain(block[1]))
            continue
        out.append(_plain(block[1]))
        out.append("")
    text = "\n".join(out)
    while "\n\n\n" in text:
        text = text.replace("\n\n\n", "\n\n")
    return text.strip() + "\n"


def expected_fields(c: dict) -> dict:
    return {
        "contract_title": c["title"],
        "contract_type": c["contract_type"],
        "client_name": CLIENT,
        "vendor_name": c["vendor"],
        "vendor_address": c["vendor_addr"],
        "start_date": c["start_iso"],
        "end_date": c["end_iso"],
        "contract_value": c["value"],
        "currency": "PHP",
        "payment_terms": c["expected"]["payment_terms"],
        "renewal_terms": c["expected"]["renewal_terms"],
        "client_signatory": f"{c['client_sig'][0]}, {c['client_sig'][1]}",
        "vendor_signatory": f"{c['vendor_sig'][0]}, {c['vendor_sig'][1]}",
        "signed_date": c["signed_iso"],
    }


def main() -> None:
    from pypdf import PdfReader

    OUT_DIR.mkdir(parents=True, exist_ok=True)
    expected = {}
    for c in CONTRACTS:
        blocks = build_blocks(c)
        pdf_path = OUT_DIR / f"{c['slug']}.pdf"
        txt_path = OUT_DIR / f"{c['slug']}.txt"
        render_pdf(c, blocks, pdf_path)
        txt_path.write_text(render_txt(blocks), encoding="utf-8")
        pages = len(PdfReader(str(pdf_path)).pages)
        expected[c["slug"]] = expected_fields(c)
        print(f"{pdf_path.name}: {pages} pages")
    (OUT_DIR / "expected_fields.json").write_text(json.dumps(expected, indent=2), encoding="utf-8")
    print(f"Wrote {OUT_DIR / 'expected_fields.json'}")


if __name__ == "__main__":
    main()
