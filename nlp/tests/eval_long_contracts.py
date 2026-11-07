#!/usr/bin/env python3
"""
Run the clause extractor on samples/long-form-contracts and score it against
expected_fields.json. Also writes <slug>.extracted.json next to each PDF so the
extracted (system-relevant) text can be shown side by side with the contract.

  nlp/.venv/Scripts/python.exe nlp/tests/eval_long_contracts.py
"""
from __future__ import annotations

import json
import re
import sys
from pathlib import Path

NLP = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(NLP))

from extract import extract_from_pdf, extract_from_text  # noqa: E402

SAMPLES = NLP.parent / "samples" / "long-form-contracts"

SYSTEM_FIELDS = [
    "contract_title",
    "contract_type",
    "client_name",
    "vendor_name",
    "vendor_address",
    "start_date",
    "end_date",
    "signed_date",
    "contract_value",
    "currency",
    "payment_terms",
    "scope",
    "penalty_clause",
    "renewal_terms",
    "termination_clause",
    "client_signatory",
    "vendor_signatory",
    "financial_obligations",
]

FREE_TEXT_CHECKS = {
    "scope": r"shall (?:furnish|provide|supply)|hereby leases",
    "penalty_clause": r"penalty|liquidated damages",
    "termination_clause": r"terminat",
}


def _norm(value) -> str:
    return re.sub(r"\s+", " ", str(value or "")).strip().lower()


def score(result: dict, expected: dict) -> list[tuple[str, bool, object, object]]:
    rows = []
    for key, want in expected.items():
        got = result.get(key)
        if isinstance(want, float):
            ok = got is not None and abs(float(got) - want) < 0.01
        else:
            ok = _norm(got) == _norm(want)
        rows.append((key, ok, got, want))
    for key, pattern in FREE_TEXT_CHECKS.items():
        got = result.get(key) or ""
        ok = bool(re.search(pattern, got, flags=re.I)) and len(got) <= 900 and "whereas" not in got.lower()
        rows.append((key, ok, got[:110] + ("..." if len(got) > 110 else ""), f"/{pattern}/ <=900 chars"))
    return rows


UNSEEN = {
    "path": NLP / "tests" / "unseen_longform_consulting.txt",
    "expected": {
        "contract_title": "Professional Consulting Services Agreement",
        "contract_type": "Service Agreement",
        "client_name": "Sunrise Hotels Group, Inc.",
        "vendor_name": "BrightPath Advisory Partners Co.",
        "vendor_address": "7F Net Lima Building, 5th Avenue, Bonifacio Global City, Taguig City",
        "start_date": "2027-02-15",
        "end_date": "2028-02-14",
        "contract_value": 6250000.0,
        "currency": "PHP",
        "payment_terms": "Milestone-based",
        "renewal_terms": "Manual renewal only",
        "client_signatory": "Teresa V. Ocampo, Chief Financial Officer",
        "vendor_signatory": "Gabriel N. Soriano, Managing Partner",
        "signed_date": "2027-02-03",
    },
}


def main() -> int:
    expected_all = json.loads((SAMPLES / "expected_fields.json").read_text(encoding="utf-8"))
    total = passed = 0
    runs = [(slug, kind, SAMPLES / f"{slug}.{kind}", expected) for slug, expected in expected_all.items() for kind in ("pdf", "txt")]
    runs.append(("unseen-consulting", "txt", UNSEEN["path"], UNSEEN["expected"]))
    for slug, kind, path, expected in runs:
        if kind == "pdf":
            result = extract_from_pdf(str(path))
        else:
            result = extract_from_text(path.read_text(encoding="utf-8"))
        rows = score(result, expected)
        ok_count = sum(1 for _, ok, _, _ in rows if ok)
        total += len(rows)
        passed += ok_count
        print(f"\n=== {path.name}  {ok_count}/{len(rows)}  (source text {len(result.get('contract_text') or ''):,} chars)")
        for key, ok, got, want in rows:
            mark = "PASS" if ok else "FAIL"
            line = f"  [{mark}] {key}: {got!r}"
            if not ok:
                line += f"   expected: {want!r}"
            print(line)
        if kind == "pdf":
            summary = {key: result.get(key) for key in SYSTEM_FIELDS}
            summary["extraction_mode"] = result.get("extraction_mode")
            summary["document_stats"] = result.get("long_form")
            summary["evidence_clauses"] = result.get("clauses")
            (SAMPLES / f"{slug}.extracted.json").write_text(json.dumps(summary, indent=2, ensure_ascii=False), encoding="utf-8")
    print(f"\nOverall: {passed}/{total} checks passed ({passed / total:.0%})")
    return 0 if passed == total else 1


if __name__ == "__main__":
    raise SystemExit(main())
