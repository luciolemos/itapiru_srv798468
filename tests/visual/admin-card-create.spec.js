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

test.describe('Admin card create persistence', () => {
    test('creates a card and keeps it listed until cleanup', async({ page }) => {
        const title = `Card E2E Create ${Date.now()}`;
        const href = `http://10.57.40.55/create-${Date.now()}/`;

        await loginAsAdmin(page);
        await closeAssistantBubbleIfVisible(page);

        await page.goto('/itapiru/admin?entity=cards&mode=new');
        await expect(page).toHaveURL(/entity=cards&mode=new/);
        await closeAssistantBubbleIfVisible(page);

        const groupSelect = page.locator('select[name="group_slug"]');
        const subgroupSelect = page.locator('select[name="subgroup_slug"]');

        await expect(groupSelect).toBeVisible();
        await expect(subgroupSelect).toBeVisible();

        const groupValue = await groupSelect.locator('option').nth(0).getAttribute('value');
        if (!groupValue) {
            throw new Error('Nenhum grupo disponível para criar card.');
        }

        await groupSelect.selectOption(groupValue);

        await expect.poll(async() => subgroupSelect.locator('option').count()).toBeGreaterThan(0);
        const subgroupValue = await subgroupSelect.locator('option').nth(0).getAttribute('value');
        if (!subgroupValue) {
            throw new Error('Nenhum subgrupo disponível para criar card.');
        }

        await subgroupSelect.selectOption(subgroupValue);
        await page.locator('input[name="order"]').fill('1');
        await page.locator('select[name="status"]').selectOption('Interno');
        await page.locator('input[name="title"]').fill(title);
        await page.locator('input[name="href"]').fill(href);
        await page.locator('input[name="icon"]').fill('bi-globe2');
        await page.locator('input[name="description"]').fill('Card criado por teste automatizado.');
        await page.locator('select[name="external"]').selectOption('0');

        await page.getByRole('button', { name: 'Criar card' }).click();
        await expect(page).toHaveURL(/entity=cards$/);

        const createdRow = page.locator('tr', { hasText: title }).first();
        await expect(createdRow).toBeVisible();
        await expect(createdRow).toContainText(href);
        await expect(createdRow).toContainText('Interno');

        await createdRow.getByRole('button', { name: 'Excluir card' }).click();
        await expect(page.locator('tr', { hasText: title })).toHaveCount(0);
    });
});
