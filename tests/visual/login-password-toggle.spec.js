// phpcs:ignoreFile
const { test, expect } = require('@playwright/test');

test.describe('Login password visibility', () => {
    test('toggles the password visibility with the eye icon', async({ page }) => {
        await page.goto('/login');

        const password = page.getByLabel('Senha', { exact: true });
        const toggle = page.locator('[data-password-toggle-icon]');

        await expect(password).toHaveAttribute('type', 'password');
        await expect(toggle).toHaveAccessibleName('Mostrar senha');
        await expect(toggle.locator('.bi')).toHaveClass(/bi-eye/);

        await toggle.click();

        await expect(password).toHaveAttribute('type', 'text');
        await expect(toggle).toHaveAttribute('aria-pressed', 'true');
        await expect(toggle).toHaveAccessibleName('Ocultar senha');
        await expect(toggle.locator('.bi')).toHaveClass(/bi-eye-slash/);

        await toggle.click();

        await expect(password).toHaveAttribute('type', 'password');
        await expect(toggle).toHaveAttribute('aria-pressed', 'false');
        await expect(toggle).toHaveAccessibleName('Mostrar senha');
    });
});
