const { test, expect } = require('@playwright/test');

test.describe('Dashboard visual regression', () => {
  test('dashboard top fold', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveScreenshot('dashboard-top.png', {
      fullPage: false,
      animations: 'disabled',
    });
  });

  test('dashboard full page', async ({ page }) => {
    await page.goto('/');
    await page.waitForLoadState('networkidle');

    await expect(page).toHaveScreenshot('dashboard-full.png', {
      fullPage: true,
      animations: 'disabled',
    });
  });
});
