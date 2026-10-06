for (const key of ['NO_PROXY', 'no_proxy'])
  process.env[key] = [process.env[key], '127.0.0.1', 'localhost'].filter(Boolean).join(',');
const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  testDir: './tests',
  testMatch: '*.spec.cjs',
  use: { baseURL: 'http://127.0.0.1:18782', trace: 'retain-on-failure' },
  webServer: {
    command: 'php tests/browser-fixture.php && php -S 127.0.0.1:18782 -t .runtime/frontend',
    url: 'http://127.0.0.1:18782/',
    reuseExistingServer: false,
  },
});
