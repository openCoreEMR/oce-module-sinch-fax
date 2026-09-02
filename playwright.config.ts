import { defineConfig, devices } from '@playwright/test';

/**
 * Playwright configuration for OpenEMR Sinch Fax module E2E tests.
 *
 * Usage:
 *   1. Start Docker: task dev:start
 *   2. Set BASE_URL or let it auto-detect: export BASE_URL=http://localhost:PORT
 *   3. Run tests: npm run test:e2e
 *
 * The BASE_URL can be:
 *   - Set explicitly via environment variable
 *   - Auto-detected from Docker (run: task dev:port)
 */
export default defineConfig({
  testDir: './e2e/tests',

  // Run tests in parallel
  fullyParallel: true,

  // Fail the build on CI if test.only is left in the source
  forbidOnly: !!process.env.CI,

  // Retry on CI only
  retries: process.env.CI ? 2 : 0,

  // Single worker for now - OpenEMR session handling can be tricky
  workers: 1,

  // Reporter configuration
  reporter: [
    ['html', { open: 'never' }],
    ['list'],
  ],

  // Shared settings for all projects
  use: {
    // Base URL from environment or default to localhost
    baseURL: process.env.BASE_URL || 'http://localhost:80',

    // Collect trace on failure
    trace: 'on-first-retry',

    // Screenshot on failure
    screenshot: 'only-on-failure',

    // Longer timeout for Docker containers that may be slow to respond
    actionTimeout: 30_000,
    navigationTimeout: 60_000,

    // Ignore HTTPS errors (self-signed certs in dev)
    ignoreHTTPSErrors: true,
  },

  // Global timeout for each test
  timeout: 120_000,

  // Expect timeout
  expect: {
    timeout: 10_000,
  },

  // Projects configuration
  projects: [
    // Setup project for authentication
    {
      name: 'setup',
      testMatch: /.*\.setup\.ts/,
    },

    // Main tests that depend on authentication
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        // Use stored authentication state
        storageState: 'e2e/.auth/user.json',
      },
      dependencies: ['setup'],
    },
  ],
});
