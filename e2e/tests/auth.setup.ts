import { test as setup, expect } from '@playwright/test';

const authFile = 'e2e/.auth/user.json';

/**
 * Authentication setup - runs once before all tests.
 *
 * Logs into OpenEMR with default credentials and saves the session
 * state for reuse by subsequent tests.
 */
setup('authenticate', async ({ page }) => {
  // Navigate to OpenEMR (redirects to login page)
  await page.goto('/');

  // Wait for login form to be visible
  await expect(page.getByPlaceholder('Username')).toBeVisible();

  // Fill in credentials
  await page.getByPlaceholder('Username').fill('admin');
  await page.getByPlaceholder('Password').fill('pass');

  // Click login button
  await page.getByRole('button', { name: /login/i }).click();

  // Wait for successful login - main tabs page loads
  await expect(page).toHaveURL(/.*tabs\/main\.php.*/);

  // Verify we're logged in by checking for the menu bar
  await expect(page.locator('#mainMenu')).toBeVisible();

  // Save authentication state
  await page.context().storageState({ path: authFile });
});
