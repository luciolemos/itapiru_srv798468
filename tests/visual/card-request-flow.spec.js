// phpcs:ignoreFile
const { test, expect } = require('@playwright/test');
const { loginAsAdmin } = require('./helpers/admin-auth');

test.describe('Card request public to admin flow', () => {
    test('submits request publicly and approves it in admin', async({ page }) => {
        const suffix = Date.now();
        const requestedTitle = 'Card E2E ' + suffix;
        const requestedUrl = 'https://example.mil.br/e2e-' + suffix;

        await page.goto('/itapiru/solicitar-card');
        await page.waitForLoadState('networkidle');
        await page.locator('select[name="requester_rank"]').selectOption({ index: 1 });

        const assistantClose = page.getByRole('button', { name: 'Fechar mensagem do Guardião' });
        if (await assistantClose.isVisible().catch(() => false)) {
            await assistantClose.click();
        }

        await page.getByLabel('Seu nome').fill('Teste E2E');
        await page.getByLabel('E-mail').fill('e2e@itapiru.local');
        await page.getByLabel('Título do card').fill(requestedTitle);
        await page.getByLabel('URL (https://)').fill(requestedUrl);

        const groupSelect = page.locator('select[name="group_slug"]');
        const subgroupSelect = page.locator('select[name="subgroup_slug"]');

        const availableGroups = await groupSelect.locator('option').evaluateAll((options) => {
            return options
                .map((option) => {
                    return { value: option.value, label: (option.textContent || '').trim() };
                })
                .filter((option) => option.value !== '');
        });

        if (availableGroups.length === 0) {
            throw new Error('Nenhum grupo disponível para teste E2E.');
        }

        const selectedGroup = availableGroups[0];
        await groupSelect.selectOption(selectedGroup.value);

        const matchingSubgroup = await subgroupSelect
            .locator('option')
            .evaluateAll(
                (options, groupLabel) => {
                    const normalizedGroup = String(groupLabel || '').toLowerCase();

                    return options
                        .map((option) => {
                            return {
                                value: option.value,
                                label: (option.textContent || '').trim(),
                            };
                        })
                        .find((option) => option.value && option.label.toLowerCase().startsWith(normalizedGroup + ' · ')) || null;
                },
                selectedGroup.label
            );

        if (!matchingSubgroup) {
            throw new Error('Nenhum subgrupo encontrado para o grupo ' + selectedGroup.label + '.');
        }

        await subgroupSelect.selectOption(matchingSubgroup.value);
        await page.getByLabel('Descrição').fill('Solicitação automatizada para validar fluxo público até aprovação admin.');

        await page.getByRole('button', { name: 'Enviar solicitação' }).click();
        await expect(page.getByText('Solicitação enviada com sucesso. Obrigado pela contribuição.')).toBeVisible();

        await loginAsAdmin(page);
        await page.goto('/itapiru/admin?entity=requests');
        await expect(page).toHaveURL(/entity=requests/);

        const requestRow = page.locator('tbody tr', { hasText: requestedTitle }).first();
        await expect(requestRow).toBeVisible();

        await requestRow.getByRole('button', { name: 'Aprovar solicitação' }).click();

        await expect(page).toHaveURL(/entity=requests/);
        await expect(page.locator('tbody tr', { hasText: requestedTitle }).first()).toContainText('Aprovado');
    });
});
