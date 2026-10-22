#!/usr/bin/env python3
"""Build the Test Lab's function-level usability tasks from the use case
document (DESIGN §39.6):

    python3 -I tools/qa-usability.py ~/krucheck-manuals

Reads src/usecase/*.md of the manuals folder (outside this public repo):
every `## <name> {uc=T10}` heading with its ```uc block, its numbered
"ขั้นตอนการใช้งาน" and its "กรณีอื่น" bullets, and writes
backend/resources/qa/usability.json. Run it again after the use case
document changes and read the diff before committing.
"""
import json
import re
import sys
from pathlib import Path

ACTOR_ORDER = {"T": 0, "S": 1, "A": 2, "X": 3}
ACTORS = {"T": "ครู", "S": "นักเรียน", "A": "ผู้ดูแลระบบ", "X": "ระบบ"}
UC_FIELDS = {
    "ผู้ใช้หลัก": "actor_text",
    "ผู้ใช้รอง": "secondary",
    "เป้าหมาย": "goal",
    "เงื่อนไขก่อนเริ่ม": "pre",
    "ผลลัพธ์": "outcome",
    "ใช้ได้บน": "platforms",
}


def clean(text: str) -> str:
    text = re.sub(r"\[\[fig:[^\]]+\]\]", "", text)
    text = re.sub(r"\(\((\d+)\)\)", r"(\1)", text)
    text = re.sub(r"\s*\{(\.[a-z]+|uc=[A-Z0-9]+)\}", "", text)
    return re.sub(r"[ \t]+", " ", text).strip()


def parse_chapter(path: Path):
    lines = path.read_text(encoding="utf-8").splitlines()
    chapter = None
    cases = []
    cur = None
    section = None
    in_uc = False
    in_fence = False
    for line in lines:
        if line.startswith("```"):
            if in_uc:
                in_uc = False
            elif line.strip() == "```uc" and cur is not None:
                in_uc = True
            else:
                in_fence = not in_fence
            continue
        if in_fence:
            continue
        if in_uc:
            key, _, value = line.partition(":")
            field = UC_FIELDS.get(key.strip())
            if field:
                cur[field] = clean(value)
            continue
        if line.startswith("# ") and "{.part}" not in line:
            chapter = clean(line[2:])
            continue
        m = re.match(r"^## (.+?)\s*\{uc=([A-Z])(\d+)\}\s*$", line)
        if m:
            cur = {
                "code": f"UC-{m.group(2)}{m.group(3)}",
                "actor": ACTORS[m.group(2)],
                "chapter": chapter or "",
                "title": clean(m.group(1)),
                "steps": [],
                "alts": [],
            }
            cases.append(cur)
            section = None
            continue
        if line.startswith("## ") or line.startswith("# "):
            cur = None
            continue
        if cur is None:
            continue
        stripped = line.strip()
        if stripped == "**ขั้นตอนการใช้งาน**":
            section = "steps"
            continue
        if stripped == "**กรณีอื่น**":
            section = "alts"
            continue
        if re.match(r"^\*\*[^*]+\*\*$", stripped):
            section = None  # another titled note under the use case
            continue
        if section == "steps":
            m = re.match(r"^\d+\.\s+(.*)$", stripped)
            if m:
                cur["steps"].append(clean(m.group(1)))
            elif stripped and cur["steps"] and line.startswith("   "):
                cur["steps"][-1] += " " + clean(stripped)
        elif section == "alts":
            m = re.match(r"^-\s+\*\*([0-9]+[ก-ฮ])\*\*\s+(.*)$", stripped)
            if m:
                cur["alts"].append({"label": m.group(1), "text": clean(m.group(2))})
            elif stripped.startswith("- "):
                cur["alts"].append({"label": str(len(cur["alts"]) + 1), "text": clean(stripped[2:])})
            elif stripped and cur["alts"] and line.startswith("  "):
                cur["alts"][-1]["text"] += " " + clean(stripped)
    return cases


def main() -> None:
    if len(sys.argv) != 2:
        sys.exit("usage: tools/qa-usability.py <manuals folder>")
    src = Path(sys.argv[1]).expanduser() / "src" / "usecase"
    cases = []
    for path in sorted(src.glob("*.md")):
        cases.extend(parse_chapter(path))
    cases.sort(key=lambda c: (ACTOR_ORDER[c["code"][3]], int(c["code"][4:])))
    seen = set()
    for c in cases:
        if c["code"] in seen:
            sys.exit(f"duplicate {c['code']}")
        seen.add(c["code"])
        if not c["steps"]:
            sys.exit(f"{c['code']} has no steps")
        for key in ("goal", "outcome"):
            if not c.get(key):
                sys.exit(f"{c['code']} has no {key}")
    out = Path(__file__).resolve().parent.parent / "backend" / "resources" / "qa" / "usability.json"
    out.write_text(json.dumps({"source": "Krucheck Use Case และการใช้งาน", "cases": cases}, ensure_ascii=False, indent=1) + "\n", encoding="utf-8")
    alts = sum(len(c["alts"]) for c in cases)
    print(f"{len(cases)} use cases, {sum(len(c['steps']) for c in cases)} steps, {alts} alternative paths -> {out}")


if __name__ == "__main__":
    main()
