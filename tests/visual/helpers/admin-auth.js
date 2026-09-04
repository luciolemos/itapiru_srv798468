const adminUser = process.env.ITAPIRU_ADMIN_USER || 'admin';
const adminPassword = process.env.ITAPIRU_ADMIN_PASSWORD || 'admin123';

async function loginAsAdmin(page)
{
    await page.goto('/login');
    await page.waitForLoadState('networkidle');

    await page.getByLabel('Usuário', { exact: true }).fill(adminUser);
    await page.getByLabel('Senha', { exact: true }).fill(adminPassword);
    await page.getByRole('button', { name: 'Entrar' }).click();
}

module.exports = {
    adminUser,
    adminPassword,
    loginAsAdmin,
};
