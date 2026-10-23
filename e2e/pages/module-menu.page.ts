import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for navigating to module via Modules menu.
 *
 * Use this to access Modules > OpenCoreEMR Sinch Fax from the main menu.
 */
export class ModuleMenuPage {
  readonly page: Page;
  readonly modulesMenu: Locator;

  constructor(page: Page) {
    this.page = page;
    this.modulesMenu = page.getByRole('button', { name: 'Modules' });
  }

  /**
   * Navigate to Sinch Fax module via Modules menu.
   */
  async navigateToSinchFax() {
    // Click Modules menu
    await this.modulesMenu.click();

    // Wait for dropdown and click on Sinch Fax
    const sinchFaxLink = this.page.getByRole('link', { name: 'OpenCoreEMR Sinch Fax' });
    await sinchFaxLink.click();

    // Wait for module to load in iframe
    await this.page.waitForTimeout(2000);
  }

  /**
   * Get the module's main iframe.
   */
  getModuleFrame() {
    return this.page.frameLocator('iframe[name^="frm_"]').first();
  }

  /**
   * Verify the module page loaded successfully.
   */
  async expectModuleLoaded() {
    const frame = this.getModuleFrame();
    // Look for module-specific content (adjust based on actual module UI)
    await expect(frame.locator('body')).toContainText(/sinch|fax/i);
  }
}
