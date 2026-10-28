import { defineConfig, devices } from '@playwright/test';
import { REQUEST_FOOTPRINT_SELECTED_ENV, requestFootprintIsSelected } from './tests/request-footprint.js';

// Evaluated first in the main process, where the command line is; workers inherit the result.
process.env[REQUEST_FOOTPRINT_SELECTED_ENV] ??= String(requestFootprintIsSelected(process.argv));

export default defineConfig({
  timeout: 120000, // 2 minutes
  testDir: './tests',
  forbidOnly: !!process.env.CI,
  retries: 0,
  fullyParallel: false,
  workers: 1,
  reporter: [
    ['list'],
    ['html', { open: 'never' }],
    ['playwright-ctrf-json-reporter', {
      outputDir: './results',
      outputFile: 'ctrf.json',
    }],
    ['allure-playwright', {
      resultsDir: './results/allure',
    }],
    ['blob', {
      outputDir: './results/blob',
    }],
  ],
  use: {
    baseURL: process.env.QIT_SITE_URL || 'http://localhost:8080',
    trace: 'retain-on-failure',
    video: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
});
