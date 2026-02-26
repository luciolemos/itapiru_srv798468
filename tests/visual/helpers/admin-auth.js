const adminUser = process.env.ITAPIRU_ADMIN_USER || 'admin';
const adminPassword = process.env.ITAPIRU_ADMIN_PASSWORD || 'admin123';

async function loginAsAdmin(page) {
  await page.goto('/itapiru/login');
  await page.waitForLoadState('networkidle');

  await page.getByLabel('Usuário').fill(adminUser);
  await page.getByLabel('Senha').fill(adminPassword);
  await page.getByRole('button', { name: 'Entrar' }).click();
}

module.exports = {
  adminUser,
  adminPassword,
  loginAsAdmin,
};
