"""
Publish the template-style lease sample as TXT + PDF, then extract labeled
fields the same way ConTrack NLP expects (ContractTemplate.md).

Run from repo root:
  python scripts/publish_template_lease_sample.py
Requires: pip install fpdf2
"""
from __future__ import annotations

import re
from pathlib import Path

from fpdf import FPDF

ROOT = Path(__file__).resolve().parent.parent
SAMPLE_TXT = ROOT / "samples" / "template-style-north-luzon-cold-storage-lease.txt"
SAMPLE_PDF = SAMPLE_TXT.with_suffix(".pdf")

MAROON = (122, 15, 20)
TAN = (228, 193, 156)
INK = (26, 26, 26)
MUTED = (85, 85, 85)


def extract_template_fields(text: str) -> dict[str, str | None]:
    """Python NLP: pull labeled lines from ContractTemplate.md samples."""
    labels = {
        "contract_title": r"Contract Title",
        "contract_type": r"Contract Type",
        "start_date": r"Effective Date",
        "end_date": r"End Date",
        "client_name": r"Client Name",
        "vendor_name": r"Vendor Name",
        "vendor_address": r"Vendor Address",
        "scope": r"Scope of Work",
        "contract_value": r"Contract Value",
        "currency": r"Currency",
        "payment_terms": r"Payment Terms",
        "penalty_clause": r"Penalty Clause",
        "renewal_terms": r"Renewal Terms",
        "termination_clause": r"Termination Clause",
        "client_signatory": r"Client Authorized Signatory",
        "vendor_signatory": r"Vendor Authorized Signatory",
        "signed_date": r"Signed Date",
    }
    out: dict[str, str | None] = {key: None for key in labels}
    for key, label in labels.items():
        match = re.search(rf"{label}\s*[:\-]\s*(.+)", text, flags=re.IGNORECASE)
        if match:
            out[key] = re.sub(r"\s+", " ", match.group(1)).strip()
    value = out.get("contract_value") or ""
    money = re.search(r"([0-9][0-9,]*(?:\.[0-9]{1,2})?)", value)
    if money:
        out["contract_value"] = money.group(1).replace(",", "")
    return out


class TemplateContractPDF(FPDF):
    def header(self) -> None:
        self.set_fill_color(*MAROON)
        self.rect(0, 0, 210, 22, "F")
        self.set_xy(14, 6)
        self.set_text_color(*TAN)
        self.set_font("Helvetica", "B", 11)
        self.cell(0, 6, "ConTrack AI  |  CONTRACT MANAGEMENT SYSTEM", ln=1)
        self.set_x(14)
        self.set_font("Helvetica", "", 8)
        self.set_text_color(255, 255, 255)
        self.cell(0, 5, "Official template sample  ·  labeled for NLP extraction")
        self.ln(10)

    def footer(self) -> None:
        self.set_y(-14)
        self.set_draw_color(*TAN)
        self.set_line_width(0.4)
        self.line(14, self.get_y(), 196, self.get_y())
        self.set_y(-12)
        self.set_font("Helvetica", "I", 8)
        self.set_text_color(120, 120, 120)
        self.cell(0, 8, f"Template-aligned sample  ·  Page {self.page_no()}", align="C")


def build_pdf(text: str, out_path: Path) -> None:
    pdf = TemplateContractPDF()
    pdf.set_auto_page_break(auto=True, margin=18)
    pdf.add_page()
    pdf.set_left_margin(14)
    pdf.set_right_margin(14)

    pdf.set_font("Helvetica", "B", 16)
    pdf.set_text_color(*MAROON)
    pdf.multi_cell(0, 8, "North Luzon Cold Storage Facility Lease Agreement 2026")
    pdf.set_font("Helvetica", "", 9)
    pdf.set_text_color(*MUTED)
    pdf.cell(0, 6, "Lease Agreement  ·  Effective 2026-07-01 to 2028-06-30  ·  PHP 4,860,000.00", ln=1)
    pdf.ln(2)
    pdf.set_draw_color(*TAN)
    pdf.set_line_width(0.8)
    pdf.line(14, pdf.get_y(), 196, pdf.get_y())
    pdf.ln(6)

    for raw in text.splitlines():
        line = raw.strip()
        if not line:
            pdf.ln(2)
            continue
        if re.match(r"^\d+\.\s+", line):
            pdf.ln(2)
            pdf.set_fill_color(249, 238, 241)
            pdf.set_text_color(*MAROON)
            pdf.set_font("Helvetica", "B", 10)
            pdf.cell(0, 8, f"  {line}", ln=1, fill=True)
            pdf.ln(1)
            continue
        if ":" in line:
            label, value = line.split(":", 1)
            pdf.set_font("Helvetica", "B", 9)
            pdf.set_text_color(*MAROON)
            pdf.cell(52, 5.5, label.strip() + ":")
            pdf.set_font("Helvetica", "", 9)
            pdf.set_text_color(*INK)
            pdf.multi_cell(0, 5.5, value.strip())
            continue
        pdf.set_font("Helvetica", "", 9)
        pdf.set_text_color(*INK)
        pdf.multi_cell(0, 5.5, line)

    pdf.output(str(out_path))


def main() -> None:
    text = SAMPLE_TXT.read_text(encoding="utf-8")
    build_pdf(text, SAMPLE_PDF)
    fields = extract_template_fields(text)
    print(f"Wrote {SAMPLE_PDF.relative_to(ROOT)}")
    print("NLP fields:")
    for key, value in fields.items():
        print(f"  {key}: {value}")


if __name__ == "__main__":
    main()
