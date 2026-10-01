// Uses the existing Playwright dependency; screenshots contain synthetic fixtures only.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
(async () => {
  const password = crypto.randomBytes(24).toString('hex');
  const seeded = spawnSync('docker', ['exec', '-i', process.env.RECONCILIATION_CONTAINER || 'demandas-test-021-glpi', 'php', '/tmp/legacy-reconciliation-fixture.php'], { input: JSON.stringify({ mode: 'seed', password }), encoding: 'utf8' });
  if (seeded.status !== 0) throw new Error('Falha na fixture isolada.');
  const timeSeed = spawnSync('docker', ['exec', '-i', process.env.RECONCILIATION_CONTAINER || 'demandas-test-021-glpi', 'php', '/tmp/legacy-reconciliation-fixture.php'], { input: JSON.stringify({ mode: 'time-seed' }), encoding: 'utf8' });
  if (timeSeed.status !== 0) throw new Error('Falha na fixture de tempo isolada.');
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } });
  const errors = [];
  page.on('pageerror', error => errors.push(error.message));
  const base = process.env.RECONCILIATION_BASE || 'http://127.0.0.1:8386';
  await page.goto(base);
  await page.locator('[name="login_name"]').fill('reconciliation-admin');
  await page.locator('[name="login_password"]').fill(password);
  await Promise.all([page.waitForURL('**/front/central.php'), page.locator('[name="submit"]').click()]);
  const out = process.env.RECONCILIATION_SCREENSHOTS || path.join(os.tmpdir(), 'demandas-reconciliation-024');
  fs.mkdirSync(out, { recursive: true });
  for (const screen of ['dashboard', 'legacy-reconciliation']) {
    await page.goto(base + '/plugins/demandas/front/' + screen + '.php');
    if (!(await page.locator('h1').textContent()).trim()) throw new Error('Tela sem título.');
    for (const dark of [false, true]) {
      await page.evaluate(dark => {
        document.documentElement.setAttribute('data-glpi-theme', dark ? 'auror_dark' : 'auror');
        document.documentElement.setAttribute('data-glpi-theme-dark', dark ? '1' : '0');
      }, dark);
      if (screen === 'legacy-reconciliation') {
        await page.locator('.legacy-conflict-details').first().click();
        const modal = page.locator('#legacyConflictDetails');
        await modal.waitFor({ state: 'visible' });
        await page.waitForTimeout(350); // Capture only after Bootstrap's fade completes.
        if (!(await modal.textContent()).includes('Motivos')) throw new Error('Detalhes do conflito não foram renderizados.');
        await page.screenshot({ path: path.join(out, `${screen}-details-${dark ? 'dark' : 'light'}.png`), fullPage: true });
        await modal.locator('[data-bs-dismiss="modal"]').first().click();
        await modal.waitFor({ state: 'hidden' });
      }
      await page.screenshot({ path: path.join(out, `${screen}-${dark ? 'dark' : 'light'}.png`), fullPage: true });
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(500); // Allow the native GLPI sidebar resize transition to finish.
    await page.screenshot({ path: path.join(out, `${screen}-mobile.png`), fullPage: true });
    if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)) throw new Error('Rolagem horizontal da página: ' + screen);
    await page.setViewportSize({ width: 1440, height: 1000 });
  }
  const timeResponse = await page.goto(base + '/plugins/demandas/front/time-entry.php');
  if (timeResponse.status() !== 200) throw new Error('Página de tempo recusada: ' + timeResponse.status());
  await page.locator('[data-bs-target="#teCreate"]').click();
  const timeModal = page.locator('#teCreate');
  await timeModal.waitFor({ state: 'visible' });
  await timeModal.locator('label[for="te-source-external"]').click();
  await timeModal.locator('.te-external-query').fill('92001');
  await timeModal.locator('.te-external-search').click();
  await page.waitForFunction(() => document.querySelector('#teCreate .te-external-results option[value="0:92001"]'));
  await timeModal.locator('.te-external-results').selectOption('0:92001');
  await page.waitForFunction(() => document.querySelector('#teCreate .te-activity option[value^="/api/v3/time_entries/activities/1"]'));
  for (const dark of [false, true]) {
    await page.evaluate(dark => {
      document.documentElement.setAttribute('data-glpi-theme', dark ? 'auror_dark' : 'auror');
      document.documentElement.setAttribute('data-glpi-theme-dark', dark ? '1' : '0');
    }, dark);
    await page.screenshot({ path: path.join(out, `time-entry-external-${dark ? 'dark' : 'light'}.png`), fullPage: true });
  }
  await page.setViewportSize({ width: 390, height: 844 });
  await page.waitForTimeout(500); // Wait for GLPI's native sidebar transition.
  await page.screenshot({ path: path.join(out, 'time-entry-external-mobile.png'), fullPage: true });
  if (await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 2)) throw new Error('Rolagem horizontal da página de tempo.');
  if (errors.length) throw new Error(errors.join('\n'));
  await browser.close();
  console.log('PASS: telas desktop/mobile, claro/escuro, sem erro JavaScript. Capturas: ' + out);
})().catch(error => { console.error(error); process.exit(1); });
