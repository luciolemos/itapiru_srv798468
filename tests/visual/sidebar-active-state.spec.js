const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/admin-auth');

test.describe('Sidebar active state consistency', () => {
  const assertSingleActive = async (page, route) => {
    await page.goto(route);
    await page.waitForLoadState('networkidle');

    await expect(page.locator('.db-menu-item.is-active')).toHaveCount(1);
    await expect(page.locator('.db-menu-item[aria-current="page"]')).toHaveCount(1);
  };

  test('public routes keep exactly one active sidebar item', async ({ page }) => {
    await assertSingleActive(page, '/itapiru');
    await assertSingleActive(page, '/itapiru/readme');
    await assertSingleActive(page, '/itapiru/contato');
    await assertSingleActive(page, '/itapiru/login');
  });

  test('admin routes keep exactly one active sidebar item', async ({ page }) => {
    await loginAsAdmin(page);
    await page.waitForURL('**/itapiru/admin**');

    await assertSingleActive(page, '/itapiru/admin?entity=sections');
    await assertSingleActive(page, '/itapiru/admin?entity=cards');
  });
});
