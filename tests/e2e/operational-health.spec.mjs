import { test, expect } from '@playwright/test';
import { assertConfigured, assertDistinctAuthorizationAccounts, env } from './env.mjs';
import { login, logout, goToOperationalHealth } from './glpi.mjs';
import { captureForManual } from './manual-screenshot.mjs';

test.describe('Central de Pendências de Integração', () => {
  test.beforeEach(() => assertConfigured(env.operator));
  test.beforeAll(() => assertDistinctAuthorizationAccounts());

  test('administração exibe os parâmetros da central', async ({ page }) => {
    test.skip(!env.admin.user || !env.admin.password, 'Configure o administrador fictício para validar a configuração.');
    await login(page, env.admin);
    const response = await page.goto('/plugins/demandas/front/config.form.php?tab=automation');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Pendências operacionais da integração' })).toBeVisible();
    await expect(page.getByLabel('Status iniciais para alerta (separados por vírgula)')).toBeVisible();
    await captureForManual(page, 'configuracao-integracao-automacao.png');
    await logout(page);
  });

  test('operador visualiza o monitoramento pessoal', async ({ page }) => {
    await login(page, env.operator);
    const response = await page.goto('/plugins/demandas/front/work-package-monitor.php');
    expect(response?.status()).toBe(200);
    await expect(page.getByRole('heading', { name: 'Minhas Work Packages abertas' })).toBeVisible();
    await captureForManual(page, 'monitoramento-work-packages.png');
    await logout(page);
  });

  test('operador autorizado acessa a central e seus filtros', async ({ page }) => {
    await login(page, env.operator);
    expect(await goToOperationalHealth(page)).toBe(200);
    await expect(page.getByRole('heading', { name: 'Pendências de integração' })).toBeVisible();
    await expect(page.locator('#operational-health-state')).toBeVisible();
    await expect(page.locator('#operational-health-rule-code')).toBeVisible();
    await expect(page.locator('#operational-health-severity')).toBeVisible();
    await expect(page.getByRole('button', { name: 'Executar verificação' })).toBeVisible();
    await captureForManual(page, 'central-pendencias-operacionais.png');
    await logout(page);
  });

  test('perfil somente leitura não pode executar ou tratar pendências', async ({ page }) => {
    test.skip(!env.viewer.user || !env.viewer.password, 'Configure o usuário visualizador fictício para validar segregação de funções.');
    await login(page, env.viewer);
    expect(await goToOperationalHealth(page)).toBe(200);
    await expect(page.getByRole('heading', { name: 'Pendências de integração' })).toBeVisible();
    await expect(page.getByRole('button', { name: 'Executar verificação' })).toHaveCount(0);
    await expect(page.getByTitle('Reconhecer')).toHaveCount(0);
    await logout(page);
  });

  test('perfil sem direitos técnicos não recebe a central', async ({ page }) => {
    test.skip(!env.restricted.user || !env.restricted.password, 'Configure o usuário restrito fictício para validar acesso negado.');
    await login(page, env.restricted);
    const status = await goToOperationalHealth(page);
    expect([200, 401, 403]).toContain(status);
    await expect(page.getByRole('heading', { name: 'Pendências de integração' })).toHaveCount(0);
  });

  test('rodada manual preserva a tratativa local', async ({ page }) => {
    test.skip(!env.isolated || !env.allowMutations, 'Ação mutável bloqueada fora de ambiente isolado confirmado.');
    await login(page, env.operator);
    await goToOperationalHealth(page);
    await page.getByRole('button', { name: 'Executar verificação' }).click();
    await expect(page.getByText(/pendência\(s\) identificada\(s\) na verificação local/i)).toBeVisible();
    const acknowledge = page.getByTitle('Reconhecer').first();
    test.skip(await acknowledge.count() === 0, 'A instância fictícia não possui uma pendência para tratar.');
    await acknowledge.click();
    await expect(page.getByText(/Situação da pendência atualizada/i)).toBeVisible();
    await expect(page.getByText(/Reconhecida em/).first()).toBeVisible();
    await logout(page);
  });
});
