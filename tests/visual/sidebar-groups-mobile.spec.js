const { test, expect } = require('@playwright/test');

test.describe('Sidebar groups collapse (mobile)', () => {
  test('toggles group items on touch in iPhone-like viewport', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile', 'Runs only on mobile project.');

    await page.goto('/itapiru');
    await page.waitForLoadState('networkidle');

    await page.locator('[data-sidebar-toggle]').first().click();
    await expect(page.locator('.db-shell')).toHaveClass(/is-sidebar-open/);

    const firstGroupToggle = page.locator('[data-menu-group-toggle]').first();
    const firstGroupItems = page.locator('[data-menu-group-items]').first();

    await expect(firstGroupToggle).toBeVisible();

    await firstGroupToggle.tap();
    await page.waitForTimeout(260);

    await expect(firstGroupToggle).toHaveAttribute('aria-expanded', 'false');
    await expect(firstGroupItems).toHaveAttribute('hidden', 'hidden');

    await firstGroupToggle.tap();
    await page.waitForTimeout(260);

    await expect(firstGroupToggle).toHaveAttribute('aria-expanded', 'true');
    await expect(firstGroupItems).not.toHaveAttribute('hidden', 'hidden');
  });
});
