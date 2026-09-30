#!/usr/bin/env python3
"""Read JSON from stdin, write extracted fields to stdout."""
from __future__ import annotations

import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
if str(ROOT) not in sys.path:
    sys.path.insert(0, str(ROOT))

from extract import extract_from_pdf, extract_from_text  # noqa: E402


def main() -> int:
    if hasattr(sys.stdout, "reconfigure"):
        sys.stdout.reconfigure(encoding="utf-8")
    raw = sys.stdin.read()
    if not raw.strip():
        print(json.dumps({"error": "empty input"}, ensure_ascii=True))
        return 1
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        print(json.dumps({"error": f"invalid json: {exc}"}, ensure_ascii=True))
        return 1

    pdf_path = str(payload.get("pdf_path") or "").strip()
    text = str(payload.get("text") or payload.get("contract_text") or "")
    try:
        if pdf_path:
            result = extract_from_pdf(pdf_path)
        else:
            result = extract_from_text(text)
    except Exception as exc:  # noqa: BLE001
        print(json.dumps({"error": str(exc), "engine": "python-ml"}, ensure_ascii=True))
        return 1
    print(json.dumps(result, ensure_ascii=True, default=str), flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
