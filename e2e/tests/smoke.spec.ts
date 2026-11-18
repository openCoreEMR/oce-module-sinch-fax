import { test, expect } from '@playwright/test';
import { ModuleMenuPage } from '../pages';

/**
 * Smoke tests to verify basic E2E infrastructure works.
 *
 * These tests verify:
 * - Authentication setup succeeded
 * - Basic navigation works
 * - Module is accessible
 */
test.describe('Smoke Tests', () => {
  test('is logged in after setup', async ({ page }) => {
    // Navigate to main page
    await page.goto('/');

    // Should be on the main tabs page (not redirected to login)
    await expect(page).toHaveURL(/.*tabs\/main\.php.*/);

    // Main menu should be visible
    await expect(page.locator('#mainMenu')).toBeVisible();
  });

  test('can navigate to Sinch Fax module via menu', async ({ page }) => {
    // Navigate to main page first
    await page.goto('/');
    await expect(page.locator('#mainMenu')).toBeVisible();

    // Use page object to navigate to module
    const moduleMenu = new ModuleMenuPage(page);
    await moduleMenu.navigateToSinchFax();

    // Verify module loaded - look for module-specific content
    await moduleMenu.expectModuleLoaded();
  });

  test('main menu items are visible', async ({ page }) => {
    await page.goto('/');

    // Check main menu items exist
    await expect(page.getByRole('button', { name: 'Admin' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Modules' })).toBeVisible();
  });
});
