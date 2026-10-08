"""Exercise native installation against fresh synthetic WordPress and dedicated MySQL storage."""

import argparse
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import time

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


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--wp-cli", type=Path, required=True)
    parser.add_argument("--lab", type=Path, required=True)
    parser.add_argument("--core-version", default="6.8.3")
    parser.add_argument(
        "--core-source", type=Path, help="Copy only core code for an offline local run"
    )
    args = parser.parse_args()
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
    env = dict(os.environ)
    env.setdefault("WP_XP_TEST_DB_USER", "root")
    env.setdefault("WP_XP_TEST_DB_PASSWORD", "")
    env["WP_XP_TEST_DB_HOST"] = host
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
    for path in ROOT.glob("*.php"):
        if path.is_symlink():
            raise ValueError("Plugin PHP source must contain no symlinks")
        shutil.copy2(path, plugin / path.name)
    copy_directory(ROOT / "assets", plugin / "assets")
    results = {"wordpress": {"version": wp("core", "version").strip()}}
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
    print(
        json.dumps({key: len(value.get("checks", [])) for key, value in results.items()}, indent=2)
    )


if __name__ == "__main__":
    main()
