import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for Admin > Config (Globals) page.
 *
 * This page has a left sidebar with configuration sections.
 * The Sinch Fax module settings appear as "OpenCoreEMR Sinch Fax Module".
 */
export class GlobalsPage {
  readonly page: Page;
  readonly adminMenu: Locator;
  readonly configMenuItem: Locator;
  readonly sidebar: Locator;

  constructor(page: Page) {
    this.page = page;
    this.adminMenu = page.getByRole('button', { name: 'Admin' });
    this.configMenuItem = page.getByRole('link', { name: 'Config' });
    this.sidebar = page.locator('.tabNav');
  }

  /**
   * Navigate to Admin > Config from main menu.
   */
  async goto() {
    // Click Admin menu
    await this.adminMenu.click();

    // Wait for dropdown to appear and click Config
    await this.configMenuItem.click();

    // Wait for Config page to load in iframe
    // The config page loads in an iframe within OpenEMR's tab system
    await this.page.waitForTimeout(2000);
  }

  /**
   * Navigate to a specific configuration section by name.
   * @param sectionName - The sidebar section name (e.g., "OpenCoreEMR Sinch Fax Module")
   */
  async navigateToSection(sectionName: string) {
    // Find the frame containing the globals configuration
    const frame = this.page.frameLocator('iframe[name^="frm_"]').first();

    // Click on the section in the sidebar
    const sectionLink = frame.getByRole('link', { name: sectionName });
    await sectionLink.click();

    // Wait for section content to load
    await this.page.waitForTimeout(1000);
  }

  /**
   * Navigate to Sinch Fax Module configuration.
   */
  async navigateToSinchFaxConfig() {
    await this.navigateToSection('OpenCoreEMR Sinch Fax Module');
  }

  /**
   * Get a form field value by label.
   * @param label - The field label text
   */
  async getFieldValue(label: string): Promise<string> {
    const frame = this.page.frameLocator('iframe[name^="frm_"]').first();
    const field = frame.locator(`label:has-text("${label}")`).locator('..').locator('input, select, textarea').first();
    return await field.inputValue();
  }

  /**
   * Set a form field value by label.
   * @param label - The field label text
   * @param value - The value to set
   */
  async setFieldValue(label: string, value: string) {
    const frame = this.page.frameLocator('iframe[name^="frm_"]').first();
    const field = frame.locator(`label:has-text("${label}")`).locator('..').locator('input, select, textarea').first();
    await field.fill(value);
  }
}
