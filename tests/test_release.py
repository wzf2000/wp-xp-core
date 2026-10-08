"""Exercise release safety failures against a disposable Git repository."""

import hashlib
import json
from pathlib import Path
import shutil
import subprocess
import tempfile
import unittest
import zipfile


class ReleaseGuards(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        (self.root / "tools").mkdir()
        shutil.copy(
            Path(__file__).resolve().parent.parent / "tools/release.py", self.root / "tools"
        )
        package = {"name": "wp-xp-core", "version": "1.0.0"}
        lock = {"name": "wp-xp-core", "version": "1.0.0", "packages": {"": package}}
        for name, value in [("package.json", package), ("package-lock.json", lock)]:
            (self.root / name).write_text(json.dumps(value))
        for name, value in {
            "wp-xp-core.php": "<?php // Plugin Name: WP XP Core\n// Version: 1.0.0\n",
            "index.php": "<?php\n",
            "README.md": "Read me\n",
            "README.en.md": "English readme\n",
            "LICENSE": "GPL\n",
            "SECURITY.md": "Private reporting\n",
            "CHANGELOG.md": "# Changes\n\n## 1.0.0\n\n- Current functionality.\n",
            "theme.json": "{}\n",
            "screenshot.png": "fixture",
        }.items():
            (self.root / name).write_text(value)
        for directory in ["tests", "node_modules", ".github"]:
            (self.root / directory).mkdir()
            (self.root / directory / "development.txt").write_text("excluded")
        self.git("init", "-q")
        self.git("remote", "add", "origin", "https://github.com/fixture/project.git")
        self.git("add", ".")
        self.git(
            "-c",
            "user.name=Fixture",
            "-c",
            "user.email=fixture@example.invalid",
            "commit",
            "-qm",
            "fixture",
        )
        self.sha = self.git("rev-parse", "HEAD").strip()

    def git(self, *args):
        return subprocess.check_output(["git", *args], cwd=self.root, text=True)

    def run_tool(self, *args, ok=True):
        result = subprocess.run(
            ["python3", "tools/release.py", *args], cwd=self.root, capture_output=True, text=True
        )
        self.assertEqual(result.returncode == 0, ok, result.stdout + result.stderr)
        return result

    def test_identity_and_repository_guards(self):
        for version in ["v1.0.0", "0.5.2", "1.0.0;evil", "../1.0.0"]:
            self.run_tool("--version", version, ok=False)
        for sha in ["bad", "0" * 40]:
            self.run_tool("--sha", sha, ok=False)
        self.run_tool("--repository", "https://example.invalid/repo", ok=False)
        (self.root / "package-lock.json").write_text("{}")
        self.run_tool(ok=False)

    def test_package_rename_identity_guards(self):
        self.run_tool("--identity-only")
        lock_path = self.root / "package-lock.json"
        original_lock = lock_path.read_text()
        lock = json.loads(original_lock)
        lock["packages"][""]["name"] = "retired-package"
        lock_path.write_text(json.dumps(lock))
        self.assertIn("Package name mismatch", self.run_tool("--identity-only", ok=False).stderr)
        lock_path.write_text(original_lock)
        header_path = self.root / "wp-xp-core.php"
        original_header = header_path.read_text()
        header_path.write_text(original_header.replace("WP XP Core", "Retired Product"))
        self.assertIn("Plugin name mismatch", self.run_tool("--identity-only", ok=False).stderr)
        header_path.write_text(original_header)
        (self.root / "reader-experience.php").write_text(original_header)
        self.assertIn(
            "Retired plugin entry remains", self.run_tool("--identity-only", ok=False).stderr
        )

    def test_determinism_and_installation_allowlist(self):
        self.run_tool()
        archive = self.root / "dist/wp-xp-core-1.0.0.zip"
        first = hashlib.sha256(archive.read_bytes()).hexdigest()
        self.run_tool()
        self.assertEqual(first, hashlib.sha256(archive.read_bytes()).hexdigest())
        with zipfile.ZipFile(archive) as bundle:
            self.assertFalse(
                any(
                    "/tests/" in name
                    or "/node_modules/" in name
                    or "/.github/" in name
                    or "/tools/" in name
                    for name in bundle.namelist()
                )
            )
        with zipfile.ZipFile(archive) as bundle:
            self.assertIn("wp-xp-core/wp-xp-core.php", bundle.namelist())
            self.assertNotIn("wp-xp-core/reader-experience.php", bundle.namelist())
            self.assertNotIn("wp-xp-core/theme.json", bundle.namelist())
            self.assertNotIn("wp-xp-core/screenshot.png", bundle.namelist())
        self.run_tool("--check-package")

    def test_nested_installation_files_and_commit_bytes(self):
        for name, content in {
            "modules/feature.php": "<?php // synthetic module\n",
            "assets/feature.js": "void 0;\n",
            "assets/development.txt": "excluded\n",
            "docs/USAGE.md": "Synthetic usage\n",
            "docs/assets/overview.svg": "<svg/>\n",
        }.items():
            path = self.root / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(content)
        self.git("add", ".")
        self.git(
            "-c",
            "user.name=Fixture",
            "-c",
            "user.email=fixture@example.invalid",
            "commit",
            "-qm",
            "nested installation fixture",
        )
        self.run_tool()
        slug = json.loads((self.root / "package.json").read_text())["name"]
        with zipfile.ZipFile(self.root / f"dist/{slug}-1.0.0.zip") as archive:
            self.assertIn(f"{slug}/modules/feature.php", archive.namelist())
            self.assertIn(f"{slug}/assets/feature.js", archive.namelist())
            self.assertIn(f"{slug}/docs/assets/overview.svg", archive.namelist())
            self.assertNotIn(f"{slug}/assets/development.txt", archive.namelist())
        (self.root / "modules/feature.php").write_text("<?php // dirty nested module\n")
        result = self.run_tool(ok=False)
        self.assertIn("Uncommitted installation source", result.stderr)

    def test_tampered_bundle_manifest_and_checksum(self):
        for name in [
            "wp-xp-core-1.0.0.zip",
            "wp-xp-core-1.0.0.manifest.json",
            "wp-xp-core-1.0.0.zip.sha256",
            "release-notes.md",
        ]:
            with self.subTest(name=name):
                self.run_tool()
                with (self.root / "dist" / name).open("ab") as output:
                    output.write(b"tampered")
                self.run_tool("--check-package", ok=False)

    def test_uncommitted_installation_source_rejected(self):
        (self.root / "index.php").write_text("<?php // dirty source\n")
        self.run_tool(ok=False)

    def test_current_changelog_required(self):
        for contents in [
            "## 0.5.2\n\nFuture notes\n\n## 1.0.0\n\nOld notes\n",
            "## 1.0.0\n\n",
        ]:
            with self.subTest(contents=contents):
                (self.root / "CHANGELOG.md").write_text(contents)
                self.git("add", "CHANGELOG.md")
                self.git(
                    "-c",
                    "user.name=Fixture",
                    "-c",
                    "user.email=fixture@example.invalid",
                    "commit",
                    "-qm",
                    "changelog fixture",
                )
                result = self.run_tool(ok=False)
                self.assertRegex(result.stderr, "current changelog section|Empty release notes")
