// phpcs:ignoreFile
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/admin-auth');

async function closeAssistantBubbleIfVisible(page)
{
    const assistantClose = page.getByRole('button', { name: 'Fechar mensagem do Guardião' });
    if (await assistantClose.isVisible().catch(() => false)) {
        await assistantClose.click();
    }
}

test.describe('Admin card edit persistence', () => {
    test('updates card URL and persists after save', async({ page }) => {
        await loginAsAdmin(page);
        await closeAssistantBubbleIfVisible(page);

        await page.goto('/itapiru/admin?entity=cards');
        await expect(page).toHaveURL(/entity=cards/);
        await closeAssistantBubbleIfVisible(page);

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
        await closeAssistantBubbleIfVisible(page);

        const hrefInput = page.locator('input[name="href"]');
        await expect(hrefInput).toBeVisible();

        const originalHref = await hrefInput.inputValue();
        const updatedHref = `http://10.57.40.55/e2e-${Date.now()}/`;

        try {
            await hrefInput.fill(updatedHref);
            await page.getByRole('button', { name: 'Salvar card' }).click();
            await expect(page).toHaveURL(/entity=cards$/);

            await page.goto(`/itapiru/admin?entity=cards&mode=edit&id=${cardId}`);
            await closeAssistantBubbleIfVisible(page);
            await expect(page.locator('input[name="href"]')).toHaveValue(updatedHref);
        } finally {
            if (page.isClosed()) {
                return;
            }

            await page.goto(`/itapiru/admin?entity=cards&mode=edit&id=${cardId}`);
            await closeAssistantBubbleIfVisible(page);

            const originalHrefInput = page.locator('input[name="href"]');
            if (await originalHrefInput.isVisible().catch(() => false)) {
                await originalHrefInput.fill(originalHref);
                await page.getByRole('button', { name: 'Salvar card' }).click();
                await expect(page).toHaveURL(/entity=cards$/);
            }
        }
    });
});
