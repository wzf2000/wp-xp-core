# Reader Experience

Independent WordPress plugin for the existing ten-level reader experience policy: daily check-in, reading dwell time, publishing, comments and author milestones. Version **1.0.0**, PHP **8.0+**, WordPress **6.0+**.

This private source tree is a release candidate for an existing configured ledger. It does not establish a new balance policy, install tables or recalculate accounts. Source or ZIP preparation is not evidence of production deployment.

## Install and use

1. Build the verified `reader-experience-1.0.0.zip` from a committed checkout and install through WordPress.
2. Have the server administrator configure the shared JSON profile outside the web root, pointing to the existing ledger and policy options. With no configuration the plugin remains inactive.
3. Stop the previous experience owner before enabling this plugin. Retain all existing balances and event records.
4. Add the configured experience shortcode to the account page, then verify check-in, history and login return with a test account.

The frontend displays experience, level and recent history. Check-in and reading require authenticated requests. PageNest Companion owns likes; Reader Experience receives the standard like event, records it once with zero XP and applies the existing author milestones. The previous like REST aliases delegate to Companion. Disabling experience does not prevent Companion likes.

The `site_tools_account_panel` filter supplies the account panel when the policy is ready and preserves the caller's existing output otherwise.

## Development and release

[Configuration and safety](docs/CONFIGURATION.md) · [Development commands](CONTRIBUTING.md) · [Changelog](CHANGELOG.md) · [License](LICENSE)

Pinned development dependencies, formatter, asset build, contracts, browser checks and deterministic package guards are self-contained. The installation ZIP includes PHP, content-hashed JS/CSS and user documentation, and excludes tests, tools and dependencies. CI runs PHP 8.0/8.2; identity rules are supplied externally.
