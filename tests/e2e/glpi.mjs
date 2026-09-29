import { expect } from '@playwright/test';

export async function login(page, account) {
  await page.goto('/front/login.php');
  const username = page.locator('input[name="login_name"]');
  if (await username.count()) {
    await username.fill(account.user);
    await page.locator('input[name="login_password"]').fill(account.password);
    await page.locator('button[type="submit"], input[type="submit"]').first().click();
  }
  const sessionExpired = page.getByText(/session has expired|sessão expirou/i);
  if (await sessionExpired.count()) {
    throw new Error('O login não criou uma sessão válida no GLPI. Use uma conta fictícia ativa com acesso à interface central; perfis ou contas somente para API, como post-only, não atendem a esta suíte.');
  }
  await expect(page.locator('input[name="login_password"]')).toHaveCount(0);
}

export async function logout(page) {
  await page.goto('/front/logout.php');
}

export async function goToOperationalHealth(page) {
  const response = await page.goto('/plugins/demandas/front/operational-health.php');
  return response?.status() ?? 0;
}
