"""Build and validate a deterministic installation bundle, without executing its code."""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import zipfile

ROOT = Path(__file__).resolve().parent.parent
SLUG = json.loads((ROOT / "package.json").read_text())["name"]
assert SLUG == "wp-xp-core", "Unknown package slug"
HEADER = "wp-xp-core.php"
VERSION_RE = r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)(?:-(?:alpha|beta|rc)\.[1-9]\d*)?"


def repository_from_remote():
    try:
        remote = subprocess.check_output(
            ["git", "remote", "get-url", "origin"], cwd=ROOT, text=True, stderr=subprocess.DEVNULL
        ).strip()
    except subprocess.CalledProcessError:
        return None
    match = re.search(r"github[.]com[:/]([^/]+/[^/]+?)(?:[.]git)?$", remote)
    return match.group(1) if match else None


def digest(data):
    return hashlib.sha256(data).hexdigest()


def identity(version, sha):
    assert re.fullmatch(VERSION_RE, version), "Invalid version"
    assert re.fullmatch(r"[0-9a-f]{40}", sha), "Invalid source SHA"
    header = (ROOT / HEADER).read_text()
    assert re.search(r"Plugin Name:\s*WP XP Core\s*(?:\n|$)", header), "Plugin name mismatch"
    assert not (ROOT / "reader-experience.php").exists(), "Retired plugin entry remains"
    header_version = re.search(r"Version:\s*(\S+)", header).group(1)
    package = json.loads((ROOT / "package.json").read_text())
    lock = json.loads((ROOT / "package-lock.json").read_text())
    assert (
        package["name"] == lock["name"] == lock["packages"][""]["name"] == SLUG
    ), "Package name mismatch"
    assert (
        header_version
        == package["version"]
        == lock["version"]
        == lock["packages"][""]["version"]
        == version
    ), "Version mismatch"
    assert (
        subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip() == sha
    ), "Source SHA mismatch"


def source_files():
    # Installation allowlist excludes development tools, tests and dependencies.
    names = {p.name for p in ROOT.glob("*.php")}
    names |= {"README.md", "README.en.md", "LICENSE", "CHANGELOG.md"}
    if (ROOT / "CONTRIBUTING.md").is_file():
        names.add("CONTRIBUTING.md")
    names |= {
        p.relative_to(ROOT).as_posix()
        for directory in ["docs", "assets", "modules"]
        for p in (ROOT / directory).rglob("*")
        if p.is_file() and installation_name(p.relative_to(ROOT).as_posix())
    }
    if SLUG == "pagenest":
        names |= {"style.css", "theme.json", "screenshot.png"}
    assert all(not (ROOT / name).is_symlink() for name in names), "Symlink in package"
    return sorted(names)


def installation_name(name):
    path = Path(name)
    if len(path.parts) == 1:
        return (
            path.suffix == ".php"
            or name in {"README.md", "README.en.md", "LICENSE", "CHANGELOG.md", "CONTRIBUTING.md"}
            or (SLUG == "pagenest" and name in {"style.css", "theme.json", "screenshot.png"})
        )
    return (
        (path.parts[0] == "modules" and path.suffix == ".php")
        or (path.parts[0] == "assets" and path.suffix in {".php", ".js", ".css", ".json", ".svg"})
        or (path.parts[0] == "docs" and path.suffix in {".md", ".svg"})
    )


def committed_sources(sha):
    tracked = subprocess.check_output(
        ["git", "ls-tree", "-r", "--name-only", sha], cwd=ROOT, text=True
    ).splitlines()
    assert {name for name in tracked if installation_name(name)} == set(
        source_files()
    ), "Installation files differ from committed tree"
    for name in source_files():
        committed = subprocess.check_output(["git", "show", f"{sha}:{name}"], cwd=ROOT)
        assert committed == (ROOT / name).read_bytes(), f"Uncommitted installation source: {name}"


def build(version, sha, repository):
    identity(version, sha)
    committed_sources(sha)
    assert repository is None or re.fullmatch(
        r"[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+", repository
    ), "Invalid repository"
    changelog = (ROOT / "CHANGELOG.md").read_text()
    section = re.search(r"^## ([^\n]+)\n(.*?)(?=^## |\Z)", changelog, re.M | re.S)
    assert section and section[1] == version, "Requested version is not current changelog section"
    notes = section[2].strip()
    assert notes, "Empty release notes"
    out = ROOT / "dist"
    out.mkdir(exist_ok=True)
    files = {name: digest((ROOT / name).read_bytes()) for name in source_files()}
    manifest = {"schema": 1, "slug": SLUG, "version": version, "source_commit": sha, "files": files}
    manifest_bytes = (json.dumps(manifest, indent=2, sort_keys=True) + "\n").encode()
    prefix = f"{SLUG}-{version}"
    archive_path = out / f"{prefix}.zip"
    with zipfile.ZipFile(
        archive_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9
    ) as archive:
        for name, data in [(name, (ROOT / name).read_bytes()) for name in files] + [
            ("release-manifest.json", manifest_bytes)
        ]:
            entry = zipfile.ZipInfo(f"{SLUG}/{name}", date_time=(1980, 1, 1, 0, 0, 0))
            entry.external_attr = 0o100644 << 16
            entry.compress_type = zipfile.ZIP_DEFLATED
            archive.writestr(entry, data)
    (out / f"{prefix}.manifest.json").write_bytes(manifest_bytes)
    (out / f"{prefix}.zip.sha256").write_text(
        f"{digest(archive_path.read_bytes())}  {archive_path.name}\n"
    )
    source = (
        f"https://github.com/{repository}/commit/{sha}" if repository else f"Local Git commit {sha}"
    )
    (out / "release-notes.md").write_text(
        f"{SLUG} {version}\n\n{notes}\n\nSource: {source}\n\nInstall the attached ZIP in WordPress. The checksum and external manifest identify the verified source and installation files. CI checks formatting, PHP syntax, unit fixtures, browser regressions and package integrity.\n"
    )
    attachments = [
        f"{prefix}.zip",
        f"{prefix}.zip.sha256",
        f"{prefix}.manifest.json",
        "release-notes.md",
    ]
    proof = {
        "version": version,
        "source_commit": sha,
        "files": {name: digest((out / name).read_bytes()) for name in attachments},
    }
    (out / "release-validation.json").write_text(json.dumps(proof, indent=2) + "\n")
    verify(version, sha)
    print(f"Built and verified {archive_path.name}: {len(files)} installation files")


def verify(version, sha):
    out = ROOT / "dist"
    prefix = f"{SLUG}-{version}"
    manifest_bytes = (out / f"{prefix}.manifest.json").read_bytes()
    manifest = json.loads(manifest_bytes)
    assert manifest["schema"] == 1 and manifest["slug"] == SLUG
    assert manifest["version"] == version and manifest["source_commit"] == sha
    assert set(manifest["files"]) == set(source_files()), "Installation allowlist mismatch"
    proof = json.loads((out / "release-validation.json").read_text())
    assert proof["version"] == version and proof["source_commit"] == sha
    expected = {
        f"{prefix}.zip",
        f"{prefix}.zip.sha256",
        f"{prefix}.manifest.json",
        "release-notes.md",
    }
    assert set(proof["files"]) == expected
    for name, checksum in proof["files"].items():
        assert digest((out / name).read_bytes()) == checksum
    assert (
        out / f"{prefix}.zip.sha256"
    ).read_text() == f"{proof['files'][prefix + '.zip']}  {prefix}.zip\n"
    with zipfile.ZipFile(out / f"{prefix}.zip") as archive:
        expected = {f"{SLUG}/{name}" for name in manifest["files"]} | {
            f"{SLUG}/release-manifest.json"
        }
        assert len(archive.namelist()) == len(expected) and set(archive.namelist()) == expected
        assert archive.testzip() is None
        assert archive.read(f"{SLUG}/release-manifest.json") == manifest_bytes
        for name, checksum in manifest["files"].items():
            assert (
                digest(archive.read(f"{SLUG}/{name}"))
                == checksum
                == digest((ROOT / name).read_bytes())
            )


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument(
        "--version",
        default=os.environ.get("RELEASE_VERSION")
        or json.loads((ROOT / "package.json").read_text())["version"],
    )
    parser.add_argument(
        "--sha",
        default=os.environ.get("SOURCE_SHA")
        or subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=ROOT, text=True).strip(),
    )
    parser.add_argument(
        "--repository",
        default=os.environ.get("REPOSITORY") or repository_from_remote(),
    )
    parser.add_argument("--identity-only", action="store_true")
    parser.add_argument("--check-package", action="store_true")
    args = parser.parse_args()
    identity(args.version, args.sha)
    if args.check_package:
        verify(args.version, args.sha)
    elif not args.identity_only:
        build(args.version, args.sha, args.repository)
