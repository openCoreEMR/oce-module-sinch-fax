import { type Page, type Locator, expect } from '@playwright/test';

/**
 * Page object for OpenEMR login page.
 */
export class LoginPage {
  readonly page: Page;
  readonly usernameInput: Locator;
  readonly passwordInput: Locator;
  readonly loginButton: Locator;

  constructor(page: Page) {
    this.page = page;
    this.usernameInput = page.getByPlaceholder('Username');
    this.passwordInput = page.getByPlaceholder('Password');
    this.loginButton = page.getByRole('button', { name: /login/i });
  }

  /**
   * Navigate to the login page.
   */
  async goto() {
    await this.page.goto('/');
    await expect(this.usernameInput).toBeVisible();
  }

  /**
   * Log in with the provided credentials.
   */
  async login(username: string, password: string) {
    await this.usernameInput.fill(username);
    await this.passwordInput.fill(password);
    await this.loginButton.click();

    // Wait for successful login
    await expect(this.page).toHaveURL(/.*tabs\/main\.php.*/);
  }

  /**
   * Log in with default development credentials.
   */
  async loginAsAdmin() {
    await this.login('admin', 'pass');
  }
}
