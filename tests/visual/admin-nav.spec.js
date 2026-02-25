const { test, expect } = require('@playwright/test');

test.describe('Admin navigation flow', () => {
  test('login, switch between sections/cards from sidebar, and keep breadcrumb in topbar', async ({ page }) => {
    const ensureSidebarOpen = async () => {
      const isMobile = await page.evaluate(() => window.matchMedia('(max-width: 1100px)').matches);
      if (!isMobile) {
        return;
      }

      const shell = page.locator('.db-shell');
      const classes = await shell.getAttribute('class');
      if (!classes || !classes.includes('is-sidebar-open')) {
        await page.locator('[data-sidebar-toggle]').first().click();
      }

      await expect(shell).toHaveClass(/is-sidebar-open/);
    };

    await page.goto('/itapiru/login');
    await page.waitForLoadState('networkidle');

    await page.getByLabel('Usuário').fill('admin');
    await page.getByLabel('Senha').fill('admin123');
    await page.getByRole('button', { name: 'Entrar' }).click();

    await expect(page).toHaveURL(/\/itapiru\/admin/);

    await expect(page.locator('.db-top-menu')).toHaveCount(0);
    await expect(page.locator('.db-navbar .db-breadcrumb')).toBeVisible();

    await ensureSidebarOpen();
    const sidebarNav = page.locator('.db-menu');
    await expect(sidebarNav.getByRole('link', { name: 'Grupos', exact: true })).toBeVisible();
    await expect(sidebarNav.getByRole('link', { name: 'Subgrupos', exact: true })).toBeVisible();
    await expect(sidebarNav.getByRole('link', { name: 'Cards', exact: true })).toBeVisible();
    await expect(sidebarNav.getByRole('button', { name: /^Sair$/i })).toBeVisible();

    const contentArea = page.locator('#db-content');
    await expect(contentArea.getByRole('button', { name: /^Sair$/i })).toHaveCount(0);

    await sidebarNav.getByRole('link', { name: 'Cards', exact: true }).click();
    await expect(page).toHaveURL(/entity=cards/);
    await expect(contentArea.getByRole('heading', { name: 'Cards' })).toBeVisible();
    await expect(contentArea.getByRole('button', { name: /^Sair$/i })).toHaveCount(0);

    await ensureSidebarOpen();
    await sidebarNav.getByRole('link', { name: 'Subgrupos', exact: true }).click();
    await expect(page).toHaveURL(/entity=subgroups/);
    await expect(contentArea.getByRole('heading', { name: 'Subgrupos' })).toBeVisible();
    await expect(contentArea.getByRole('button', { name: /^Sair$/i })).toHaveCount(0);

    await ensureSidebarOpen();
    await sidebarNav.getByRole('link', { name: 'Grupos', exact: true }).click();
    await expect(page).toHaveURL(/entity=groups/);
    await expect(contentArea.getByRole('heading', { name: 'Grupos' })).toBeVisible();
    await expect(contentArea.getByRole('button', { name: /^Sair$/i })).toHaveCount(0);
  });
});
