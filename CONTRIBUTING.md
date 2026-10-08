# Development

Use a Git checkout. The source directory contains all pinned development configuration and does not depend on another repository's installed tools.

```sh
npm ci --ignore-scripts
python3 -m pip install -r requirements-dev.txt
npm run format
npm run format:check
npm test
npx playwright install --only-shell chromium
npm run test:frontend
npm run identity:check
npm run build
```

PHP uses the pinned Prettier PHP formatter; JS/CSS/JSON/YAML and Markdown use Prettier, Python uses pinned Black. The format check verifies generated content hashes without rebuilding them. The browser fixtures use synthetic responses and block external network requests. No unit or browser fixture changes a real balance.

For a release, first commit the complete candidate and run:

```sh
npm run package
npm run package:check
git tag v1.1.1
```

Version identity, source SHA, committed installation bytes, the file allowlist, checksums and the manifest are verified. Tag the verified release commit as `v1.1.1`. A local repository without a remote is supported: omit `REPOSITORY`, and release notes record the local Git commit. For a GitHub release, `REPOSITORY` can be supplied explicitly or inferred from an optional GitHub remote; no account name is embedded. The manifest is published after hashed resources are built. CI preserves ZIP, checksum and validation proof. Deployment is a separately authorized operation.

`IDENTITY_RULES` is an externally configured JSON string array from Actions variables or secrets. The scanner always verifies itself against a synthetic marker, reports matching file paths only, and scans the current source tree including generated resources.
