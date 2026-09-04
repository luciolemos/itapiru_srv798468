const { test, expect } = require('@playwright/test');

test.describe('Dashboard visual regression', () => {
    test('restores the Guardian position before the deferred script runs', async({ page }) => {
        await page.addInitScript(() => {
            localStorage.setItem('dashboard_guardian_position', JSON.stringify({ x: 150, y: 210 }));
        });
        await page.route('**/assets/js/sidebar-toggle.js**', async(route) => {
            await route.fulfill({
                contentType: 'application/javascript',
                body: '',
            });
        });

        await page.goto('/');

        const assistant = page.locator('.db-floating-assistant');
        await expect(assistant).toHaveCSS('left', '150px');
        await expect(assistant).toHaveCSS('top', '210px');
    });

    test('dashboard top fold', async({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveScreenshot('dashboard-top.png', {
            fullPage: false,
            animations: 'disabled',
        });
    });

  test('dashboard full page', async({ page }) => {
        await page.goto('/');
        await page.waitForLoadState('networkidle');

        await expect(page).toHaveScreenshot('dashboard-full.png', {
            fullPage: true,
            animations: 'disabled',
        });
    });
});
