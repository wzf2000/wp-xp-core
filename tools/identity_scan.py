"""Scan the current source tree using externally supplied identity rules."""

import json
import os
from pathlib import Path
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parent.parent
IGNORED = {
    ".git",
    ".venv",
    "node_modules",
    "dist",
    ".runtime",
    "test-results",
    "playwright-report",
    "__pycache__",
}


def matches(path, rules, relative_name=None):
    data = (str(relative_name or path.name).encode("utf-8") + b"\0" + path.read_bytes()).lower()
    return any(rule.encode("utf-8").lower() in data for rule in rules)


def self_test():
    with tempfile.TemporaryDirectory() as directory:
        fixture = Path(directory) / "fixture.txt"
        fixture.write_text("Synthetic-Identity-Marker")
        assert matches(fixture, ["synthetic-identity-marker"])
        assert not matches(fixture, ["other-neutral-marker"])
        named_fixture = Path(directory) / "Synthetic-Filename-Marker.txt"
        named_fixture.write_text("Neutral contents")
        assert matches(named_fixture, ["synthetic-filename-marker"])
        assert not matches(named_fixture, ["other-neutral-marker"])


def main():
    self_test()
    rules = json.loads(os.environ.get("IDENTITY_RULES", "") or "[]")
    if not isinstance(rules, list) or any(
        not isinstance(rule, str) or not rule.strip() for rule in rules
    ):
        raise ValueError("IDENTITY_RULES must be a JSON list of nonempty strings")
    if (ROOT / ".git").exists():
        names = (
            subprocess.check_output(
                ["git", "ls-files", "--cached", "--others", "--exclude-standard", "-z"], cwd=ROOT
            )
            .decode()
            .split("\0")
        )
        paths = [ROOT / name for name in names if name]
    else:
        paths = list(ROOT.rglob("*"))
    failures = []
    for path in paths:
        relative = path.relative_to(ROOT)
        if any(part in IGNORED for part in relative.parts) or not path.exists():
            continue
        if path.is_symlink():
            raise ValueError("Symlink in source tree: " + str(relative))
        if path.is_file() and matches(path, rules, relative):
            failures.append(str(relative))
    if failures:
        raise ValueError("External identity rules matched source files:\n" + "\n".join(failures))
    print("Identity scanner self-test passed; " + str(len(rules)) + " external rules checked")


if __name__ == "__main__":
    main()
