"""Exercise native installation against fresh synthetic WordPress and dedicated MySQL storage."""

import argparse
import ctypes
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import time
import uuid
import zipfile

ROOT = Path(__file__).resolve().parent.parent
DATABASE = "wp_xp_core_ci"
CORE_PHP = (
    "index.php",
    "wp-activate.php",
    "wp-blog-header.php",
    "wp-comments-post.php",
    "wp-cron.php",
    "wp-links-opml.php",
    "wp-load.php",
    "wp-login.php",
    "wp-mail.php",
    "wp-settings.php",
    "wp-signup.php",
    "wp-trackback.php",
    "xmlrpc.php",
)


def copy_directory(source, target):
    if source.is_symlink() or any(path.is_symlink() for path in source.rglob("*")):
        raise ValueError("Synthetic source must contain no symlinks")
    shutil.copytree(source, target)


def upgrade_payload(path, checksum):
    """Validate the complete immutable public bundle before executing any old code."""
    if path.is_symlink() or not path.is_file():
        raise ValueError("Upgrade archive must be a regular file")
    if not re.fullmatch(r"[0-9a-fA-F]{64}", checksum or ""):
        raise ValueError("Upgrade archive requires an explicit SHA-256")
    if hashlib.sha256(path.read_bytes()).hexdigest() != checksum.lower():
        raise ValueError("Upgrade archive checksum mismatch")
    payload = {}
    with zipfile.ZipFile(path) as archive:
        entries = archive.infolist()
        if len(entries) > 1000 or sum(entry.file_size for entry in entries) > 128 * 1024 * 1024:
            raise ValueError("Upgrade archive exceeds bounded installation size")
        for entry in entries:
            name = entry.filename
            parts = name.split("/")
            mode = entry.external_attr >> 16
            if (
                len(parts) < 2
                or parts[0] != "wp-xp-core"
                or any(part in {"", ".", ".."} for part in parts)
                or "\\" in name
                or "\x00" in name
                or entry.is_dir()
                or stat.S_ISLNK(mode)
                or stat.S_IFMT(mode) not in {0, stat.S_IFREG}
                or entry.flag_bits & 1
            ):
                raise ValueError("Unsafe upgrade archive entry")
            relative = "/".join(parts[1:])
            if relative in payload:
                raise ValueError("Duplicate upgrade archive entry")
            payload[relative] = archive.read(entry)
    manifest = json.loads(payload.pop("release-manifest.json"))
    import release

    if (
        manifest.get("schema") != 1
        or manifest.get("slug") != "wp-xp-core"
        or not re.fullmatch(release.VERSION_RE, manifest.get("version", ""))
        or not re.fullmatch(r"[0-9a-f]{40}", manifest.get("source_commit", ""))
        or not isinstance(manifest.get("files"), dict)
        or set(manifest["files"]) != set(payload)
        or "wp-xp-core.php" not in payload
    ):
        raise ValueError("Invalid upgrade release manifest")
    for name, content in payload.items():
        if (
            not release.installation_name(name)
            or manifest["files"][name] != hashlib.sha256(content).hexdigest()
        ):
            raise ValueError("Upgrade manifest payload mismatch")
    header = payload["wp-xp-core.php"].decode("utf-8")
    if not re.search(r"Version:\s*" + re.escape(manifest["version"]) + r"\s*(?:\n|$)", header):
        raise ValueError("Upgrade header version differs from manifest")
    return payload, manifest


def exchange_directories(first, second):
    # Both directories are on the same owned /tmp filesystem. Linux's exchange
    # keeps the plugin path continuously present and never runs activation hooks.
    library = ctypes.CDLL(None, use_errno=True)
    rename = library.renameat2
    rename.argtypes = [ctypes.c_int, ctypes.c_char_p, ctypes.c_int, ctypes.c_char_p, ctypes.c_uint]
    rename.restype = ctypes.c_int
    if rename(-100, os.fsencode(first), -100, os.fsencode(second), 2) != 0:
        raise OSError(ctypes.get_errno(), "Atomic upgrade directory exchange failed")


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wp-cli", type=Path, required=True)
    parser.add_argument("--lab", type=Path, required=True)
    parser.add_argument("--core-version", default="6.8.3")
    parser.add_argument(
        "--core-source", type=Path, help="Copy only core code for an offline local run"
    )
    parser.add_argument(
        "--scale", action="store_true", help="Opt in to owned 10k/50k synthetic read benchmarks"
    )
    parser.add_argument(
        "--bbpress-version", help="Opt in to a verified official stable bbPress version"
    )
    parser.add_argument("--upgrade-from", type=Path, help="Run only an isolated old-ZIP upgrade")
    parser.add_argument("--upgrade-sha256", help="Required immutable old-ZIP SHA-256")
    args = parser.parse_args()
    if args.bbpress_version is not None and not re.fullmatch(
        r"(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)", args.bbpress_version
    ):
        raise ValueError("bbPress requires an explicit stable major.minor.patch version")
    if args.bbpress_version and args.upgrade_from:
        raise ValueError("Optional bbPress checks cannot be combined with an old-ZIP upgrade")
    if bool(args.upgrade_from) != bool(args.upgrade_sha256) or (args.upgrade_from and args.scale):
        raise ValueError(
            "Upgrade requires both ZIP and checksum, and cannot be combined with scale"
        )
    old_payload, old_manifest = (
        upgrade_payload(args.upgrade_from, args.upgrade_sha256)
        if args.upgrade_from
        else (None, None)
    )
    lab = args.lab.resolve()
    if lab.parent != Path("/tmp") or args.lab.is_symlink() or lab.exists():
        raise ValueError("Use a new direct /tmp lab directory")
    if os.environ.get("WP_XP_TEST_DATABASE") != DATABASE:
        raise ValueError("Explicit WP_XP_TEST_DATABASE=wp_xp_core_ci required")
    host = os.environ.get("WP_XP_TEST_DB_HOST", "127.0.0.1:3306")
    if host.startswith("localhost:/tmp/"):
        socket = Path(host.split(":", 1)[1])
        if (
            socket.resolve() != socket
            or not socket.exists()
            or not stat.S_ISSOCK(socket.stat().st_mode)
        ):
            raise ValueError("Use an exact existing temporary Unix socket")
    else:
        match = re.fullmatch(r"127\.0\.0\.1:([0-9]{1,5})", host)
        if not match or not 1 <= int(match[1]) <= 65535:
            raise ValueError("Synthetic database must use explicit loopback TCP or a /tmp socket")
    if not args.wp_cli.is_file() or args.wp_cli.is_symlink():
        raise ValueError("Use an existing WP-CLI PHAR")
    os.umask(0o077)
    lab.mkdir()
    site = lab / "site"
    site.mkdir()
    owner_token = uuid.uuid4().hex
    owner_file = lab / ".wp-xp-core-test-owner"
    owner_file.write_text(
        json.dumps({"database": DATABASE, "site": str(site.resolve()), "token": owner_token}) + "\n"
    )
    env = dict(os.environ)
    env.setdefault("WP_XP_TEST_DB_USER", "root")
    env.setdefault("WP_XP_TEST_DB_PASSWORD", "")
    env["WP_XP_TEST_DB_HOST"] = host
    env["WP_XP_TEST_OWNER_FILE"] = str(owner_file)
    env["WP_CLI_DISABLE_AUTO_CHECK_UPDATE"] = "1"
    command = [
        "php",
        str(args.wp_cli.resolve()),
        "--skip-packages",
        "--no-color",
        "--path=" + str(site),
    ]

    def failure(stage, result):
        diagnostic = result.stderr.strip()
        for key in ["WP_XP_TEST_DB_PASSWORD", "WP_XP_TEST_DB_USER"]:
            if env.get(key):
                diagnostic = diagnostic.replace(env[key], "[redacted]")
        raise RuntimeError(stage + " failed: " + diagnostic)

    def wp(*arguments, mode=None):
        current = dict(env)
        if mode:
            current["WP_XP_TEST_MODE"] = mode
        try:
            result = subprocess.run(
                command + list(arguments), env=current, text=True, capture_output=True, timeout=180
            )
        except subprocess.TimeoutExpired:
            # A timeout exception otherwise includes command arguments such as --dbpass.
            raise RuntimeError("Synthetic WordPress command timed out") from None
        if result.returncode:
            failure("WordPress " + " ".join(arguments[:2]), result)
        return result.stdout

    # Credentials stay in the environment, never in SQL text, evidence or captured output.
    bootstrap = lab / "create-database.php"
    bootstrap.write_text(
        """<?php
if (getenv('WP_XP_TEST_DATABASE') !== 'wp_xp_core_ci') {
    throw new RuntimeException('Dedicated synthetic database required.');
}
try {
    $host = getenv('WP_XP_TEST_DB_HOST');
    [$hostname, $endpoint] = explode(':', $host, 2);
    $socket = $hostname === 'localhost' ? $endpoint : null;
    $port = $socket ? 0 : (int) $endpoint;
    $db = new mysqli($hostname, getenv('WP_XP_TEST_DB_USER'), getenv('WP_XP_TEST_DB_PASSWORD'), '', $port, $socket);
    $count = $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='wp_xp_core_ci'")->fetch_row()[0];
    if ((int) $count !== 0) {
        throw new RuntimeException('Dedicated database already contains tables.');
    }
    $db->query('CREATE DATABASE IF NOT EXISTS wp_xp_core_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
} catch (Throwable $error) {
    fwrite(STDERR, 'Could not prepare an empty dedicated synthetic database. Existing tables are never reused.');
    exit(1);
}
"""
    )
    result = subprocess.run(
        ["php", str(bootstrap)], env=env, text=True, capture_output=True, timeout=30
    )
    if result.returncode:
        failure("Dedicated database preparation", result)
    bootstrap.unlink()
    if args.core_source:
        source = args.core_source.resolve()
        for name in ["wp-admin", "wp-includes"]:
            copy_directory(source / name, site / name)
        for name in CORE_PHP:
            path = source / name
            if not path.is_file() or path.is_symlink():
                raise ValueError("Required core PHP source is missing or symlinked")
            shutil.copy2(path, site / name)
        (site / "wp-content/plugins").mkdir(parents=True)
    else:
        wp("core", "download", "--version=" + args.core_version)
    wp(
        "config",
        "create",
        "--dbname=" + DATABASE,
        "--dbhost=" + host,
        "--dbuser=" + env["WP_XP_TEST_DB_USER"],
        "--dbpass=" + env["WP_XP_TEST_DB_PASSWORD"],
        "--dbprefix=synthetic_",
        "--skip-check",
        "--skip-salts",
    )
    wp("config", "set", "WP_ENVIRONMENT_TYPE", "local")
    wp(
        "core",
        "install",
        "--url=http://127.0.0.1:18842",
        "--title=Synthetic WP XP Core CI",
        "--admin_user=fixture-author",
        "--admin_password=synthetic-local-only",
        "--admin_email=author@example.invalid",
        "--skip-email",
    )
    wp("option", "add", "wp_xp_core_test_owner", owner_token, "--autoload=no")
    for username in ["fixture-reader", "fixture-concurrent"]:
        wp(
            "user",
            "create",
            username,
            username + "@example.invalid",
            "--role=subscriber",
            "--user_pass=synthetic-local-only",
        )
    plugin = site / "wp-content/plugins/wp-xp-core"
    plugin.mkdir(parents=True)
    import release

    if old_payload is not None:
        for name, content in old_payload.items():
            target = plugin / name
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(content)
    else:
        for name in release.source_files():
            path = ROOT / name
            if path.is_symlink():
                raise ValueError("Installation source must contain no symlinks")
            target = plugin / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
    # No core language pack is implied. The lab's low-priority locale can still be
    # overridden by WordPress's real locale switcher for English-catalog tests.
    # WP 6.0 switches the locale filter only; do not pin determine_locale independently.
    mu_plugins = site / "wp-content/mu-plugins"
    mu_plugins.mkdir(exist_ok=True)
    (mu_plugins / "xp-owned-test-locale.php").write_text(
        "<?php\n"
        "if (DB_NAME !== 'wp_xp_core_ci' || wp_get_environment_type() !== 'local' || "
        "get_option('wp_xp_core_test_owner') !== " + json.dumps(owner_token) + ") return;\n"
        "add_filter('locale', static fn($locale) => 'zh_CN', 0);\n"
        "add_filter('determine_locale', static fn($locale) => get_locale(), 0);\n"
    )
    locale_probe = lab / "locale-probe.php"
    locale_probe.write_text(
        "<?php\n"
        "if (PHP_SAPI !== 'cli' || DB_NAME !== 'wp_xp_core_ci' || "
        "wp_get_environment_type() !== 'local' || get_option('wp_xp_core_test_owner') !== "
        + json.dumps(owner_token)
        + ") throw new RuntimeException('Owned synthetic locale probe required');\n"
        "echo determine_locale();\n"
    )
    try:
        if wp("eval-file", str(locale_probe)).strip() != "zh_CN":
            raise RuntimeError("Owned synthetic locale fixture did not initialize")
    finally:
        locale_probe.unlink()
    results = {"wordpress": {"version": wp("core", "version").strip()}}
    if old_payload is not None:
        upgrade_fixture = str(ROOT / "tests/wordpress-upgrade.php")
        wp("plugin", "activate", "wp-xp-core", "--user=fixture-author")
        results["upgrade_seed"] = json.loads(wp("eval-file", upgrade_fixture, mode="seed"))
        (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
        staged = plugin.with_name("wp-xp-core-upgrade-staged")
        staged.mkdir()
        for name in release.source_files():
            path = ROOT / name
            if path.is_symlink() or any(parent.is_symlink() for parent in path.parents):
                raise ValueError("Upgrade source must contain no symlinks")
            target = staged / name
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copy2(path, target)
        exchange_directories(plugin, staged)
        results["upgrade_verify"] = json.loads(wp("eval-file", upgrade_fixture, mode="verify"))
        wp("plugin", "deactivate", "wp-xp-core", "--user=fixture-author")
        wp("plugin", "activate", "wp-xp-core", "--user=fixture-author")
        results["upgrade_restart"] = json.loads(wp("eval-file", upgrade_fixture, mode="restart"))
        results["upgrade_from"] = old_manifest
        # Preserve the verified old manifest, rather than duplicate every file in evidence.
        results["upgrade_from"].pop("files")
        (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
        shutil.rmtree(staged)
        print(json.dumps({"upgrade": results}, indent=2))
        return
    fixture = str(ROOT / "tests/wordpress-install.php")
    for mode in ["install", "verify"]:
        results[mode] = json.loads(wp("eval-file", fixture, mode=mode))
        (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    # Independent fresh WordPress processes start requests at the same barrier.
    parallel_env = dict(env, WP_XP_TEST_BARRIER=str(time.time() + 1.0))
    processes = [
        subprocess.Popen(
            command + ["eval-file", str(ROOT / "tests/wordpress-checkin.php")],
            env=parallel_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        for _ in range(2)
    ]
    clients = []
    try:
        for process in processes:
            output, errors = process.communicate(timeout=60)
            if process.returncode:
                failure(
                    "Concurrent check-in",
                    subprocess.CompletedProcess([], process.returncode, output, errors),
                )
            clients.append(json.loads(output))
    finally:
        for process in processes:
            if process.poll() is None:
                process.terminate()
                process.wait(timeout=10)
    results["concurrency"] = json.loads(wp("eval-file", fixture, mode="concurrency-verify"))
    results["concurrency"]["clients"] = clients
    (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    for stage in (
        "management",
        "policy",
        "growth",
        "achievement-rewards",
        "notifications",
        "fairness",
        "product",
        "insights",
        "storage",
        "portable",
    ):
        results[stage] = json.loads(
            wp("eval-file", str(ROOT / ("tests/wordpress-" + stage + ".php")))
        )
        (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    # Exercise optional task XP with two independent administrator requests.
    parallel_env = dict(env, WP_XP_TEST_BARRIER=str(time.time() + 1.0))
    reward_fixture = str(ROOT / "tests/wordpress-achievement-concurrent.php")
    processes = [
        subprocess.Popen(
            command + ["eval-file", reward_fixture],
            env=parallel_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        for _ in range(2)
    ]
    clients = []
    try:
        for process in processes:
            output, errors = process.communicate(timeout=60)
            if process.returncode:
                failure(
                    "Concurrent achievement",
                    subprocess.CompletedProcess([], process.returncode, output, errors),
                )
            clients.append(json.loads(output))
    finally:
        for process in processes:
            if process.poll() is None:
                process.terminate()
                process.wait(timeout=10)
    results["achievement-concurrency"] = json.loads(wp("eval-file", reward_fixture, mode="verify"))
    results["achievement-concurrency"]["clients"] = clients
    (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    parallel_env = dict(env, WP_XP_TEST_BARRIER=str(time.time() + 1.0))
    retry_fixture = str(ROOT / "tests/wordpress-comment-retry-concurrent.php")
    processes = [
        subprocess.Popen(
            command + ["eval-file", retry_fixture],
            env=parallel_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        for _ in range(2)
    ]
    clients = []
    try:
        for process in processes:
            output, errors = process.communicate(timeout=60)
            if process.returncode:
                failure(
                    "Concurrent comment compensation",
                    subprocess.CompletedProcess([], process.returncode, output, errors),
                )
            clients.append(json.loads(output))
    finally:
        for process in processes:
            if process.poll() is None:
                process.terminate()
                process.wait(timeout=10)
    results["comment-retry-concurrency"] = json.loads(wp("eval-file", retry_fixture, mode="verify"))
    results["comment-retry-concurrency"]["clients"] = clients
    configuration_fixture = str(ROOT / "tests/wordpress-configuration-concurrent.php")
    wp("eval-file", configuration_fixture, mode="prepare")
    parallel_env = dict(env, WP_XP_TEST_BARRIER=str(time.time() + 1.0))
    processes = [
        subprocess.Popen(
            command + ["eval-file", configuration_fixture],
            env=parallel_env,
            text=True,
            stdout=subprocess.PIPE,
            stderr=subprocess.PIPE,
        )
        for _ in range(2)
    ]
    clients = []
    try:
        for process in processes:
            output, errors = process.communicate(timeout=60)
            if process.returncode:
                failure(
                    "Concurrent configuration",
                    subprocess.CompletedProcess([], process.returncode, output, errors),
                )
            clients.append(json.loads(output))
    finally:
        for process in processes:
            if process.poll() is None:
                process.terminate()
                process.wait(timeout=10)
    if sorted(client["result"] for client in clients) != ["applied", "conflict"]:
        raise RuntimeError("Concurrent preview must apply once and reject one stale revision")
    results["configuration-concurrency"] = json.loads(
        wp("eval-file", configuration_fixture, mode="verify")
    )
    results["configuration-concurrency"]["clients"] = clients
    (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    provider_fixture = str(ROOT / "tests/wordpress-provider-concurrent.php")
    wp("eval-file", provider_fixture, mode="prepare")
    races = {}
    for operation in ["record", "revoke", "hold"]:
        race_env = dict(env, WP_XP_TEST_BARRIER=str(time.time() + 1.0), WP_XP_TEST_MODE=operation)
        processes = [
            subprocess.Popen(
                command + ["eval-file", provider_fixture],
                env=race_env,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            for _ in range(2)
        ]
        clients = []
        try:
            for process in processes:
                output, errors = process.communicate(timeout=60)
                if process.returncode:
                    failure(
                        "Concurrent provider " + operation,
                        subprocess.CompletedProcess([], process.returncode, output, errors),
                    )
                clients.append(json.loads(output))
        finally:
            for process in processes:
                if process.poll() is None:
                    process.terminate()
                    process.wait(timeout=10)
        if operation in ["record", "revoke"]:
            if sorted(client.get("duplicate") for client in clients) != [False, True]:
                raise RuntimeError("Provider race must commit once and return one duplicate")
        elif (
            sum(client.get("saved") is True for client in clients) != 1
            or sum(client.get("error") == "conflict" for client in clients) != 1
        ):
            raise RuntimeError("Hold CAS must commit once and reject one stale revision")
        races[operation] = clients
    results["provider-concurrency"] = json.loads(wp("eval-file", provider_fixture, mode="verify"))
    results["provider-concurrency"]["clients"] = races
    if args.scale:
        scale_env = dict(env, WP_XP_SCALE_TEST="1", WP_XP_TEST_OWNER_FILE=str(owner_file))
        scale = subprocess.run(
            command + ["eval-file", str(ROOT / "tests/wordpress-scale.php")],
            env=scale_env,
            text=True,
            capture_output=True,
            timeout=600,
        )
        if scale.returncode:
            failure("Owned scale workload", scale)
        results["scale"] = json.loads(scale.stdout)
    if args.bbpress_version:
        # All normal dependency-free stages finish before the optional plugin is installed.
        wp("plugin", "install", "bbpress", "--version=" + args.bbpress_version)
        actual = wp("plugin", "get", "bbpress", "--field=version").strip()
        if actual != args.bbpress_version:
            raise RuntimeError("Installed bbPress version differs from the requested version")
        checksum = wp("plugin", "verify-checksums", "bbpress", "--strict")
        wp("plugin", "activate", "bbpress", "--user=fixture-author")
        results["bbpress"] = json.loads(wp("eval-file", str(ROOT / "tests/wordpress-bbpress.php")))
        results["bbpress"]["version"] = actual
        results["bbpress"]["checksums"] = checksum.strip()
        (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
        bbpress_race = str(ROOT / "tests/wordpress-bbpress-concurrent.php")
        wp("eval-file", bbpress_race, mode="prepare")
        processes = [
            subprocess.Popen(
                command + ["eval-file", bbpress_race],
                env=dict(env, WP_XP_TEST_MODE=operation),
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
            )
            for operation in ["reward", "moderate"]
        ]
        clients = []
        try:
            for process in processes:
                output, errors = process.communicate(timeout=60)
                if process.returncode:
                    failure(
                        "Concurrent bbPress moderation",
                        subprocess.CompletedProcess([], process.returncode, output, errors),
                    )
                clients.append(json.loads(output))
        finally:
            for process in processes:
                if process.poll() is None:
                    process.terminate()
                    process.wait(timeout=10)
        results["bbpress-concurrency"] = json.loads(wp("eval-file", bbpress_race, mode="verify"))
        results["bbpress-concurrency"]["clients"] = clients
    (lab / "results.json").write_text(json.dumps(results, indent=2) + "\n")
    print(
        json.dumps({key: len(value.get("checks", [])) for key, value in results.items()}, indent=2)
    )


if __name__ == "__main__":
    main()
