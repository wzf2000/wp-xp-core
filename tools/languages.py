"""Compile the reviewed gettext catalogs without network access or third-party tools."""

from pathlib import Path
import ast
import json
import struct
import sys

ROOT = Path(__file__).resolve().parent.parent


def entries(path):
    result = {}
    current = {}
    field = None
    for line in path.read_text().splitlines() + [""]:
        if line.startswith("#"):
            continue
        if line.startswith(("msgid ", "msgstr ")):
            field, value = line.split(" ", 1)
            current[field] = ast.literal_eval(value)
        elif line.startswith('"') and field:
            current[field] += ast.literal_eval(line)
        elif not line.strip() and current:
            result[current["msgid"]] = current.get("msgstr", "")
            current = {}
            field = None
    return result


def compile_catalog(path):
    translations = entries(path)
    keys = sorted(translations)
    originals = [s.encode() for s in keys]
    values = [translations[s].encode() for s in keys]
    count = len(keys)
    start = 28 + count * 16
    offsets = []
    payload = b""
    for strings in [originals, values]:
        for value in strings:
            offsets.extend([len(value), start + len(payload)])
            payload += value + b"\0"
    header = struct.pack("<7I", 0x950412DE, 0, count, 28, 28 + count * 8, 0, 0)
    output = header + struct.pack("<" + "I" * len(offsets), *offsets) + payload
    target = path.with_suffix(".mo")
    if "--check" in sys.argv:
        assert target.read_bytes() == output, "Recompile language catalog: " + str(path)
    else:
        target.write_bytes(output)
    messages = {key: [value] for key, value in translations.items() if key}
    messages[""] = {"domain": "wp-xp-core", "lang": path.stem.replace("wp-xp-core-", "", 1)}
    jed = (
        json.dumps(
            {"domain": "wp-xp-core", "locale_data": {"messages": messages}},
            ensure_ascii=False,
            indent=2,
        )
        + "\n"
    ).encode()
    if "--check" in sys.argv:
        assert path.with_suffix(".json").read_bytes() == jed, "Recompile JavaScript catalog"
    else:
        path.with_suffix(".json").write_bytes(jed)


if __name__ == "__main__":
    for catalog in (ROOT / "languages").glob("*.po"):
        compile_catalog(catalog)
    required = json.loads((ROOT / "languages/js-strings.json").read_text())
    translated = entries(ROOT / "languages/wp-xp-core-en_US.po")
    missing = [text for text in required if not translated.get(text)]
    if missing:
        raise SystemExit("Missing JavaScript translations: " + repr(missing))
