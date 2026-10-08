"""Check trust boundaries and execute the publication verifier on hostile bundles."""

import hashlib
import json
import os
from pathlib import Path
import subprocess
import tempfile
import unittest
import zipfile

ROOT = Path(__file__).resolve().parent.parent


class ReleasePipeline(unittest.TestCase):
    def test_ci_is_readonly_and_aggregate_cannot_skip_failure(self):
        workflow = (ROOT / ".github/workflows/ci.yml").read_text()
        self.assertIn("  pull_request:", workflow)
        self.assertIn("  push:", workflow)
        self.assertIn("  workflow_call:", workflow)
        for forbidden in (
            "pull_request_target:",
            "secrets.",
            "contents: write",
            "secrets: inherit",
        ):
            self.assertNotIn(forbidden, workflow)
        self.assertIn("persist-credentials: false", workflow)
        self.assertIn("name: Required checks", workflow)
        self.assertIn("if: always()", workflow)
        self.assertIn("needs: verify", workflow)
        self.assertIn('run: test "$VERIFY_RESULT" = success', workflow)

    def test_publishing_has_no_untrusted_execution(self):
        workflow = (ROOT / ".github/workflows/release.yml").read_text()
        self.assertIn("default: false", workflow)
        self.assertIn('run: test "$SOURCE_REF" = refs/heads/main', workflow)
        self.assertIn("uses: ./.github/workflows/ci.yml", workflow)
        publish = workflow.split("\n  publish:\n", 1)[1]
        self.assertIn("needs: [resolve, verify]", publish)
        self.assertIn("contents: write", publish)
        for forbidden in (
            "actions/checkout",
            "npm ",
            "pip ",
            "tools/",
            "run-id:",
            "workflow_run:",
            "pull_request_target:",
        ):
            self.assertNotIn(forbidden, publish)
        self.assertIn("wp-xp-core-release-${{ needs.resolve.outputs.sha }}", publish)
        self.assertIn("github.rest.git.createRef", publish)
        self.assertIn("draft: true", publish)
        self.assertLess(publish.index("Remote digest differs"), publish.index("draft: false"))
        self.assertLess(publish.index("Tag no longer matches"), publish.index("draft: false"))

    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.release = self.root / "release"
        self.release.mkdir()
        self.version = "1.3.1"
        self.sha = "a" * 40
        self.prefix = "wp-xp-core-" + self.version
        self.files = {
            name: b"fixture"
            for name in (
                "wp-xp-core.php",
                "config.php",
                "settings.php",
                "README.md",
                "README.en.md",
                "LICENSE",
                "CHANGELOG.md",
                "CONTRIBUTING.md",
                "SECURITY.md",
            )
        }
        self.files["wp-xp-core.php"] = b"<?php\n// Version: 1.3.1\n"
        text = (ROOT / ".github/workflows/release.yml").read_text()
        script = text.split("          python3 - <<'PY'\n", 1)[1].split("          PY\n", 1)[0]
        self.script = "\n".join(line[10:] for line in script.splitlines())

    def bundle(self, extra=None, symlink=False, duplicate=False):
        files = self.files.copy()
        if extra:
            files[extra] = b"unexpected"
        manifest = {
            "schema": 1,
            "slug": "wp-xp-core",
            "version": self.version,
            "source_commit": self.sha,
            "files": {n: hashlib.sha256(d).hexdigest() for n, d in files.items()},
        }
        data = json.dumps(manifest).encode()
        (self.release / (self.prefix + ".manifest.json")).write_bytes(data)
        archive = self.release / (self.prefix + ".zip")
        with zipfile.ZipFile(archive, "w") as z:
            for name, content in files.items():
                item = zipfile.ZipInfo("wp-xp-core/" + name)
                item.external_attr = (0o120777 if symlink else 0o100644) << 16
                z.writestr(item, content)
            z.writestr("wp-xp-core/release-manifest.json", data)
            if duplicate:
                z.writestr("wp-xp-core/README.md", b"fixture")
        digest = hashlib.sha256(archive.read_bytes()).hexdigest()
        (self.release / (self.prefix + ".zip.sha256")).write_text(f"{digest}  {archive.name}\n")
        (self.release / "release-notes.md").write_text("Fixture notes")
        proof = {
            "version": self.version,
            "source_commit": self.sha,
            "files": {
                p.name: hashlib.sha256(p.read_bytes()).hexdigest()
                for p in self.release.iterdir()
                if p.name != "release-validation.json"
            },
        }
        (self.release / "release-validation.json").write_text(json.dumps(proof))

    def verify(self, success):
        result = subprocess.run(
            ["python3", "-c", self.script],
            cwd=self.root,
            env={**os.environ, "RELEASE_VERSION": self.version, "SOURCE_SHA": self.sha},
            capture_output=True,
            text=True,
        )
        self.assertEqual(result.returncode == 0, success, result.stderr)

    def test_accepts_complete_verified_bundle(self):
        self.bundle()
        self.verify(True)

    def test_rejects_unallowed_and_traversal_paths_even_with_valid_hashes(self):
        for name in (
            "../outside.php",
            "assets/../../outside.php",
            "tools/executable.py",
            "assets/./app.js",
            "/absolute.php",
        ):
            with self.subTest(name=name):
                self.bundle(extra=name)
                self.verify(False)

    def test_rejects_symlink_and_duplicate_zip_entries(self):
        self.bundle(symlink=True)
        self.verify(False)
        self.bundle(duplicate=True)
        self.verify(False)

    def test_rejects_tampered_notes_and_source(self):
        self.bundle()
        (self.release / "release-notes.md").write_text("tampered")
        self.verify(False)
        self.bundle()
        self.sha = "b" * 40
        self.verify(False)

    def test_rejects_extra_attachment_and_filesystem_symlink(self):
        self.bundle()
        extra = self.release / "run.sh"
        extra.write_text("unexpected")
        self.verify(False)
        extra.unlink()
        notes = self.release / "release-notes.md"
        original = self.root / "notes"
        notes.rename(original)
        notes.symlink_to(original)
        self.verify(False)


if __name__ == "__main__":
    unittest.main()
