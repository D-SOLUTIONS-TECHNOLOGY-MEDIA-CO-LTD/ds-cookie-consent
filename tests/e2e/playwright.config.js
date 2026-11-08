// @ts-check
const { defineConfig } = require('@playwright/test');

module.exports = defineConfig({
  testDir: __dirname,
  testMatch: /.*\.spec\.js/,
  timeout: 90_000,
  workers: 1, // tests share one WordPress database and the per-IP log rate limit
  retries: 0,
  reporter: [['list']],
  use: {
    baseURL: process.env.DSCC_BASE_URL || 'http://localhost:8888',
    trace: 'off',
    locale: 'vi-VN',
  },
});
