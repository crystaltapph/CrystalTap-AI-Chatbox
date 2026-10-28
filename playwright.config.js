const { defineConfig } = require('@playwright/test');
module.exports = defineConfig({
  globalSetup: './tests/browser/global-setup.js',
  testDir: './tests/browser',
  workers: 1,
  timeout: 45000,
  use: {baseURL: 'http://localhost:8080', headless: true, trace: 'retain-on-failure', launchOptions: {executablePath: process.env.CTAI_CHROMIUM || (require('node:fs').existsSync('/usr/bin/chromium')?'/usr/bin/chromium':undefined), args:['--no-sandbox']}},
  reporter: [['list']],
  outputDir: 'test-results',
});
