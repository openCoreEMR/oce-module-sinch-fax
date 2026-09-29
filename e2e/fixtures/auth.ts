import { test as base, expect } from '@playwright/test';

/**
 * Extended test fixture with authentication helpers.
 *
 * Tests using this fixture have access to an authenticated page.
 * The authentication state is loaded from the setup phase.
 */
export const test = base.extend<{
  authenticatedPage: typeof base;
}>({
  // The page fixture already uses storageState from config
  // This is just a semantic wrapper for clarity
});

export { expect };

/**
 * Default credentials for OpenEMR development environment.
 */
export const credentials = {
  username: 'admin',
  password: 'pass',
} as const;

/**
 * Path to stored authentication state.
 */
export const authFile = 'e2e/.auth/user.json';
