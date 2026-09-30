#!/usr/bin/env python3
"""Show what the model sees in a PDF, then the fields it fills.

Usage:
  nlp\\.venv\\Scripts\\python.exe nlp\\inspect_pdf.py path\\to\\contract.pdf
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from extract import classify_chunks, extract_from_pdf, pdf_to_text, segment  # noqa: E402

FIELDS = [
    "contract_title",
    "contract_type",
    "client_name",
    "vendor_name",
    "start_date",
    "end_date",
    "contract_value",
    "payment_terms",
    "scope",
    "penalty_clause",
    "renewal_terms",
]


def main(argv: list[str]) -> int:
    if len(argv) < 2:
        print("Usage: python nlp/inspect_pdf.py path/to/contract.pdf")
        return 1
    pdf = Path(argv[1])
    if not pdf.is_file():
        print(f"File not found: {pdf}")
        return 1

    text = pdf_to_text(str(pdf))
    print(f"FILE: {pdf.name}")
    print(f"TEXT CHARACTERS: {len(text.strip())}")
    if len(text.strip()) < 40:
        print("No selectable text. This PDF is likely scanned/image-only. The model cannot train or extract it without OCR.")
        return 2

    print("\n--- FIRST 800 CHARACTERS ---\n")
    print(text[:800])
    print("\n--- PREDICTED CLAUSES ---\n")
    scored = classify_chunks(segment(text))
    for chunk, label, conf in scored[:25]:
        preview = " ".join(chunk.split())[:160]
        print(f"[{label:12} {conf:.2f}] {preview}")

    result = extract_from_pdf(str(pdf))
    print("\n--- FILLED FIELDS ---\n")
    print(json.dumps({k: result.get(k) for k in FIELDS}, ensure_ascii=False, indent=2, default=str))
    empty = [k for k in FIELDS if not result.get(k)]
    if empty:
        print("\nEmpty fields:", ", ".join(empty))
        print("Copy the matching sentence from the PDF text into nlp/extra_examples.jsonl with a label, then run nlp/train.py")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
