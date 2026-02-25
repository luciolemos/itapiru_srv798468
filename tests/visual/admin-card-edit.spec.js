const { test, expect } = require('@playwright/test');

test.describe('Admin card edit persistence', () => {
  test('updates card URL and persists after save', async ({ page }) => {
    await page.goto('/itapiru/login');
    await page.waitForLoadState('networkidle');

    await page.getByLabel('Usuário').fill('admin');
    await page.getByLabel('Senha').fill('admin123');
    await page.getByRole('button', { name: 'Entrar' }).click();

    await page.goto('/itapiru/admin?entity=cards');
    await expect(page).toHaveURL(/entity=cards/);

    const editLink = page.getByRole('link', { name: 'Editar card' }).first();
    await expect(editLink).toBeVisible();
    const editHref = await editLink.getAttribute('href');
    if (!editHref) {
      throw new Error('Link de edição do card não encontrado.');
    }

    const idMatch = editHref.match(/id=(\d+)/);
    if (!idMatch) {
      throw new Error(`Não foi possível extrair id do card de: ${editHref}`);
    }

    const cardId = idMatch[1];

    await editLink.click();
    await expect(page).toHaveURL(new RegExp(`entity=cards&mode=edit&id=${cardId}`));

    const hrefInput = page.locator('input[name="href"]');
    await expect(hrefInput).toBeVisible();

    const originalHref = await hrefInput.inputValue();
    const updatedHref = `http://10.57.40.55/e2e-${Date.now()}/`;

    try {
      await hrefInput.fill(updatedHref);
      await page.getByRole('button', { name: 'Salvar card' }).click();
      await expect(page).toHaveURL(/entity=cards$/);

      await page.goto(`/itapiru/admin?entity=cards&mode=edit&id=${cardId}`);
      await expect(page.locator('input[name="href"]')).toHaveValue(updatedHref);
    } finally {
      await page.locator('input[name="href"]').fill(originalHref);
      await page.getByRole('button', { name: 'Salvar card' }).click();
      await expect(page).toHaveURL(/entity=cards$/);
    }
  });
});
