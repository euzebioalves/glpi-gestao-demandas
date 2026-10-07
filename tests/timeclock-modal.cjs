// Isolated GLPI 11.0.9 only; run with --baseline before applying the fix.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { spawnSync } = require('node:child_process');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const assert = require('node:assert/strict');
(async () => {
  const password = crypto.randomBytes(24).toString('hex');
  const seeded = spawnSync('docker', ['exec', '-i', 'demandas-test-021-glpi', 'php', '/tmp/timeclock-modal-fixture.php'], { input: JSON.stringify({ password }), encoding: 'utf8' });
  assert.equal(seeded.status, 0, seeded.stdout + seeded.stderr);
  const users = JSON.parse(seeded.stdout);
  const browser = await chromium.launch({ channel: 'msedge', headless: true });
  const out = path.join(os.tmpdir(), 'demandas-timeclock-0241');
  fs.mkdirSync(out, { recursive: true });
  try {
    for (const role of ['admin', 'internal', 'client']) {
      const context = await browser.newContext({ viewport: { width: 1366, height: 768 } });
      const page = await context.newPage();
      const errors = [];
      page.on('pageerror', error => errors.push(error.message));
      await page.goto('http://127.0.0.1:8386');
      await page.locator('[name="login_name"]').fill(users[role].login);
      await page.locator('[name="login_password"]').fill(password);
      await Promise.all([page.waitForNavigation(), page.locator('[name="submit"]').click()]);
      const response = await page.goto('http://127.0.0.1:8386/plugins/demandas/front/timeclock.php?year=2026&month=10');
      if (role === 'client') { assert.equal(response.status(), 403); console.log('PASS: perfil cliente sem direito bloqueado'); await context.close(); continue; }
      assert.equal(response.status(), 200);
      await page.locator('[data-tm-date="2026-10-07"]').click();
      const modal = page.locator('#tmDay');
      await modal.waitFor({ state: 'visible' });
      // Existing punch + three added rows, exactly as in the reported incident.
      for (let i = 0; i < 3; i++) await page.locator('#tm-add').click();
      await page.waitForTimeout(350);
      const geometry = () => page.evaluate(() => {
        const modal = document.querySelector('#tmDay');
        const body = modal.querySelector('.modal-body');
        const footer = modal.querySelector('.modal-footer');
        const bounds = footer.getBoundingClientRect();
        const content = modal.querySelector('.modal-content').getBoundingClientRect();
        const buttons = [...footer.querySelectorAll('button')].map(button => {
          const r = button.getBoundingClientRect();
          const hit = document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          return r.top >= 0 && r.bottom <= innerHeight && r.left >= 0 && r.right <= innerWidth && (hit === button || button.contains(hit));
        });
        return { footerVisible: buttons.every(Boolean) && bounds.bottom <= content.bottom + 1, overflowX: body.scrollWidth > body.clientWidth + 1, canScroll: body.scrollHeight > body.clientHeight, footerTop: bounds.top, footerBottom: bounds.bottom, viewportHeight: innerHeight };
      });
      if (process.argv.includes('--baseline')) {
        const before = await geometry();
        assert.equal(before.footerVisible, false, 'O teste deve reproduzir os botões inacessíveis.');
        await page.screenshot({ path: path.join(out, 'before.png') });
        console.log('REPRODUCED:', JSON.stringify(before)); return;
      }
      for (const [width, height] of [[1920,1080], [1366,768], [1280,600], [1024,768], [768,1024], [390,844], [320,568], [844,390], [683,384]]) {
        await page.setViewportSize({ width, height });
        await page.waitForTimeout(350);
        for (const dark of [false, true]) {
          await page.evaluate(dark => {
            document.documentElement.setAttribute('data-glpi-theme', dark ? 'auror_dark' : 'auror');
            document.documentElement.setAttribute('data-glpi-theme-dark', dark ? '1' : '0');
          }, dark);
          const state = await geometry();
          assert.ok(state.footerVisible, JSON.stringify({ role, width, height, dark, ...state }));
          assert.equal(state.overflowX, false, `Overflow ${width}x${height}`);
          if (width === 390 || (width === 1366 && role === 'admin')) await page.screenshot({ path: path.join(out, `${role}-${width}-${dark ? 'dark' : 'light'}.png`) });
        }
        console.log(`PASS: ${role} ${width}x${height}, claro/escuro, ações visíveis`);
      }
      // More rows and expanded absence must not push footer out of the viewport.
      await page.locator('#tm-add').click();
      await page.locator('#tm-absence-toggle').click();
      assert.ok((await geometry()).footerVisible);
      await page.locator('#tm-absence-remove').click();
      await page.locator('.tm-record .tm-remove').last().click();
      const values = ['07:00', '12:00', '13:00', '17:00'];
      for (let i = 0; i < 4; i++) await page.locator('#tm-records input[type="time"]').nth(i).fill(values[i]);
      assert.deepEqual(await page.locator('#tm-records select').evaluateAll(nodes => nodes.map(n => n.value)), ['entrada_manha','saida_manha','entrada_tarde','saida_tarde']);
      await modal.getByRole('button', { name: 'Salvar alterações' }).click();
      await page.waitForURL('**/timeclock.php?**');
      await page.locator('[data-tm-date="2026-10-07"]').click();
      await modal.waitFor({ state: 'visible' });
      assert.equal(await page.locator('.tm-record').count(), 4);
      assert.deepEqual(await page.locator('#tm-records input[type="time"]').evaluateAll(nodes => nodes.map(n => n.value)), values);
      assert.equal(await page.locator('#tm-records input[name$="[nsr]"]').first().inputValue(), 'QA-1');
      assert.deepEqual(errors, []);
      console.log('PASS: salvamento e reabertura de quatro marcações, sem erros JS: ' + role);
      await context.close();
    }
  } finally { await browser.close(); }
  console.log('Capturas fictícias: ' + out);
})().catch(error => { console.error(error); process.exit(1); });
