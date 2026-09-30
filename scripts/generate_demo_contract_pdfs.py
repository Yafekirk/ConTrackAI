"""Generate demo contract PDFs from samples/demo-contracts/demo-*.txt (run from repo root)."""
from pathlib import Path

from fpdf import FPDF


class ContractPDF(FPDF):
    def footer(self) -> None:
        self.set_y(-12)
        self.set_font("Helvetica", "I", 8)
        self.set_text_color(100, 100, 100)
        self.cell(0, 8, f"Page {self.page_no()}", align="C")


def txt_to_pdf(txt_path: Path, pdf_path: Path) -> None:
    text = txt_path.read_text(encoding="utf-8")
    pdf = ContractPDF()
    pdf.set_auto_page_break(auto=True, margin=18)
    pdf.add_page()
    pdf.set_font("Helvetica", "", 10)
    pdf.set_text_color(30, 30, 30)
    for line in text.splitlines():
        pdf.multi_cell(0, 5.5, line)
    pdf.output(str(pdf_path))


def main() -> None:
    root = Path(__file__).resolve().parent.parent
    demo_dir = root / "samples" / "demo-contracts"
    if not demo_dir.is_dir():
        raise SystemExit(f"Missing directory: {demo_dir}")

    for txt in sorted(demo_dir.glob("demo-*.txt")):
        out = txt.with_suffix(".pdf")
        txt_to_pdf(txt, out)
        print(f"Wrote {out.relative_to(root)}")


if __name__ == "__main__":
    main()
