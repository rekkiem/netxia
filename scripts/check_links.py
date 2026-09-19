#!/usr/bin/env python3
"""Validate internal HTML/PHP links in the Netxia project.

This script checks for missing local files and broken in-page anchors, while
ignoring template placeholders and vendor documentation examples.
"""

from __future__ import annotations

import os
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IGNORE_DIRS = {".git", "node_modules", "vendor"}
IGNORE_PATTERNS = (
    "blog/_template",
    "php/phpmailer",
)

HREF_PATTERN = re.compile(r'''(?:href|src)\s*=\s*["']([^"']+)["']''', re.IGNORECASE)
ID_PATTERN = re.compile(r'''id\s*=\s*["']([^"']+)["']''', re.IGNORECASE)


def should_skip(path: Path) -> bool:
    rel = path.relative_to(ROOT).as_posix()
    if rel.startswith("."):
        return True
    if any(part in IGNORE_DIRS for part in path.parts):
        return True
    if any(rel.startswith(prefix) for prefix in IGNORE_PATTERNS):
        return True
    return False


def collect_files() -> list[Path]:
    files: list[Path] = []
    for path in ROOT.rglob("*"):
        if not path.is_file():
            continue
        if path.suffix.lower() not in {".html", ".php"}:
            continue
        if should_skip(path):
            continue
        files.append(path)
    return sorted(files)


def clean_ref(raw: str) -> str | None:
    if not raw:
        return None
    ref = raw.strip()
    if not ref or ref.startswith(("mailto:", "tel:", "javascript:", "data:", "http://", "https://", "//")):
        return None
    if "${" in ref or "{{" in ref:
        return None
    return ref


def resolve_target(file_path: Path, ref: str) -> Path | None:
    ref = ref.split("#", 1)[0].split("?", 1)[0]
    if not ref:
        return None
    if ref.startswith("/"):
        return (ROOT / ref.lstrip("/")).resolve()
    return (file_path.parent / ref).resolve()


def main() -> int:
    files = collect_files()
    missing_files: list[tuple[str, str]] = []
    missing_anchors: list[tuple[str, str]] = []
    ids_cache: dict[Path, set[str]] = {}

    for file_path in files:
        try:
            text = file_path.read_text(encoding="utf-8", errors="ignore")
        except OSError:
            continue

        ids_cache[file_path] = set(ID_PATTERN.findall(text))
        for raw_ref in HREF_PATTERN.findall(text):
            ref = clean_ref(raw_ref)
            if ref is None:
                continue
            if ref.startswith("#"):
                anchor = ref[1:]
                if anchor and anchor not in ids_cache[file_path]:
                    missing_anchors.append((file_path.relative_to(ROOT).as_posix(), ref))
                continue

            target = resolve_target(file_path, ref)
            if target is None:
                continue
            if not target.exists():
                missing_files.append((file_path.relative_to(ROOT).as_posix(), ref))

    if missing_files or missing_anchors:
        print("Broken internal links detected:")
        if missing_files:
            print("\nMissing files:")
            for file_name, ref in missing_files:
                print(f"- {file_name} -> {ref}")
        if missing_anchors:
            print("\nMissing anchors:")
            for file_name, ref in missing_anchors:
                print(f"- {file_name} -> {ref}")
        return 1

    print(f"Checked {len(files)} HTML/PHP files. No broken internal links found.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
