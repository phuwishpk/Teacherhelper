#!/usr/bin/env python3
"""Copy the Krucheck manuals into the assistant's knowledge (DESIGN §37).

    python3 -I tools/assistant-manuals.py ~/krucheck-manuals

Reads the Markdown sources of the user manual (src/user) and the use case
document (src/usecase) from the manuals folder, which stays outside this
public repo, drops what only the PDF needs (front matter, screenshot and
diagram blocks, figure references, layout classes) and writes one file per
chapter to backend/resources/assistant/manual/. The assistant picks the
chapters that fit each question (AssistantKnowledge). Run it again after the
manuals change, then read the diff before committing: nothing here may hold
personal or demo data.
"""
import pathlib
import re
import sys

SOURCES = {"user": "คู่มือการใช้งาน", "usecase": "Use Case"}


def clean(text: str) -> str:
    text = re.sub(r"\A---\n.*?\n---\n", "", text, flags=re.S)
    text = re.sub(r"```(shots|ucd)[^\n]*\n.*?```", "", text, flags=re.S)
    text = re.sub(r"<div[^>]*></div>", "", text)
    text = re.sub(r"\[\[fig:[^\]]+\]\]", "", text)
    text = re.sub(r"\(\((\d+)\)\)", r"(\1)", text)
    text = re.sub(r"\s*\{(\.[a-z]+|uc=[A-Z0-9]+)\}", "", text)
    text = re.sub(r"^:::\s*\w+\s*(.*)$", lambda m: f"**{m.group(1)}**" if m.group(1) else "", text, flags=re.M)
    text = re.sub(r"^:::\s*$", "", text, flags=re.M)
    text = re.sub(r"\n{3,}", "\n\n", text)
    return text.strip() + "\n"


def main() -> None:
    root = pathlib.Path(sys.argv[1]).expanduser()
    out = pathlib.Path(__file__).resolve().parent.parent / "backend/resources/assistant/manual"
    out.mkdir(parents=True, exist_ok=True)
    for old in out.glob("*.md"):
        old.unlink()
    for folder, label in SOURCES.items():
        for src in sorted((root / "src" / folder).glob("*.md")):
            body = clean(src.read_text(encoding="utf-8"))
            if len(body) < 200:
                continue
            (out / f"{folder}-{src.stem}.md").write_text(f"<!-- {label}: {src.name} -->\n{body}", encoding="utf-8")
    print(f"wrote {len(list(out.glob('*.md')))} chapters to {out}")


if __name__ == "__main__":
    main()
