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

test.describe('Admin card preview dialog', () => {
    test('opens and closes the card preview from the list', async({ page }) => {
        await loginAsAdmin(page);
        await closeAssistantBubbleIfVisible(page);

        await page.goto('/admin?entity=cards&mode=list&per_page=50');
        await expect(page).toHaveURL(/entity=cards/);
        await closeAssistantBubbleIfVisible(page);

        const previewButton = page.getByRole('button', { name: 'Visualizar card' }).first();
        const previewDialog = page.locator('dialog.db-preview-dialog').first();

        await expect(previewButton).toBeVisible();
        await expect(previewDialog).toBeAttached();

        await previewButton.scrollIntoViewIfNeeded();
        await previewButton.click();

        await expect(previewDialog).toBeVisible();
        await expect(previewDialog).toHaveAttribute('open', '');
        await expect(previewDialog.getByRole('heading', { name: 'Pré-visualização do card' })).toBeVisible();

        await previewDialog.getByRole('button', { name: 'Fechar' }).click();
        await expect(previewDialog).not.toBeVisible();
    });
});
