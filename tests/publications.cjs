// Disposable GLPI 11 only. API/comment/user fixtures are entirely synthetic.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const fs = require('node:fs');
const path = require('node:path');
const base = process.env.PUBLICATIONS_BASE || 'http://127.0.0.1:8191';
const container = process.env.PUBLICATIONS_CONTAINER || 'demandas-sge028-glpi';
if (!/^http:\/\/(127\.0\.0\.1|localhost):8191$/.test(base) || container !== 'demandas-sge028-glpi') throw new Error('Use a base isolada 028.');
function fixture(input, legacy = false) {
    const r = spawnSync('docker', ['exec', '-i', container, 'php', '/tmp/' + (legacy ? 'legacy-reconciliation-fixture.php' : 'publications-fixture.php')], { input: JSON.stringify(input), encoding: 'utf8' });
    if (r.status !== 0) throw new Error(r.stderr || r.stdout);
    return JSON.parse(r.stdout);
}
(async () => {
    const password = crypto.randomBytes(24).toString('hex');
    const legacy = fixture({ mode: 'seed', password }, true);
    const seed = fixture({ mode: 'seed' });
    fixture({ mode: 'api-mode', value: 'ok' });
    const browser = await chromium.launch({ channel: 'msedge', headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 1440, height: 1100 } });
        const page = await context.newPage();
        const errors = []; page.on('pageerror', e => errors.push(e.message));
        async function login(page, role) {
            await page.goto(base);
            await page.locator('[name="login_name"]').fill('reconciliation-' + role);
            await page.locator('[name="login_password"]').fill(password);
            await Promise.all([page.waitForURL(url => /\/(front\/central\.php|servicecatalog|helpdesk)/i.test(url.pathname)), page.locator('[name="submit"]').click()]);
        }
        const ticketUrl = base + '/front/ticket.form.php?id=' + seed.ticket + '&forcetab=GlpiPlugin%5CDemandas%5CTicketDemand%241';
        const endpoint = base + '/plugins/demandas/front/work-package-publications.php';
        await login(page, 'admin');
        await page.goto(ticketUrl);
        const open = page.locator('.demandas-publications-open');
        await open.waitFor();
        assert((await page.locator('body').innerText()).includes('Pessoa Teste admin'));
        await page.locator('[data-bs-target^="#demandas-event-details-"]').first().click();
        const eventModal = page.locator('.modal.show');
        await eventModal.waitFor();
        assert((await eventModal.innerText()).includes('conciliado por Pessoa Teste admin'));
        assert(!(await eventModal.innerText()).includes('por usuário #'));
        await eventModal.locator('[data-bs-dismiss="modal"]').first().click();
        await eventModal.waitFor({ state: 'hidden' });
        await open.click();
        const modal = page.locator('#demandas-publications-modal');
        const button = modal.locator('.demandas-publications-refresh');
        await page.waitForFunction(() => !document.querySelector('.demandas-publications-refresh').disabled);
        await button.click();
        await page.waitForFunction(() => document.querySelector('.demandas-publications-status').textContent.includes('Consulta concluída'), null, { timeout: 60000 });
        const result = fixture({ mode: 'snapshot', ticket: seed.ticket });
        const report = JSON.parse(result.report);
        assert.equal(report.tasks.length, 3);
        assert.equal(report.tasks.find(t => t.id === 93003).releases.length, 0, 'PR-only task');
        assert.equal(report.tasks.find(t => t.id === 93002).releases[0].version, 'v1.112.0-beta.198');
        assert(report.tasks.find(t => t.id === 93004).releases.some(r => r.environment === 'Produção' && r.version === 'v1.112.0'));
        assert.equal(await modal.locator('tbody a[target="_blank"]').count(), 3);
        assert.equal(await modal.locator('script').count(), 0);
        assert((await modal.innerText()).includes('<b>texto</b>'));
        const out = process.env.PUBLICATIONS_SCREENSHOTS || path.join(__dirname, '../docs/assets/manual');
        fs.mkdirSync(out, { recursive: true });
        for (const width of [1440, 390]) {
            await page.setViewportSize({ width, height: width === 390 ? 844 : 1100 });
            for (const dark of [false, true]) {
                await page.evaluate(dark => {
                    document.documentElement.setAttribute('data-glpi-theme', dark ? 'auror_dark' : 'auror');
                    document.documentElement.setAttribute('data-glpi-theme-dark', dark ? '1' : '0');
                }, dark);
                await page.waitForTimeout(300);
                await page.screenshot({ path: path.join(out, `sge-publications-${width}-${dark ? 'dark' : 'light'}.png`) });
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2), 'Horizontal overflow');
            }
        }
        // Each failed run must preserve the complete report and audit event count.
        for (const mode of ['unauthorized', 'forbidden', 'identity', 'repeat', 'timeout']) {
            fixture({ mode: 'api-mode', value: mode });
            await button.click();
            await page.waitForFunction(() => document.querySelector('.demandas-publications-status').textContent.includes('Não foi possível concluir'), null, { timeout: 60000 });
            assert.deepEqual(fixture({ mode: 'snapshot', ticket: seed.ticket }), result, mode + ': changed saved data');
        }
        fixture({ mode: 'api-mode', value: 'ok' });
        const denied = await context.request.post(endpoint, { form: { ticket: seed.ticket, wp: 93001, action: 'start' } });
        assert.equal(denied.status(), 403, 'CSRF');
        await modal.locator('[data-bs-dismiss="modal"]').first().click();
        await page.goto(ticketUrl);
        await page.locator('.demandas-publications-open').waitFor();
        assert((await page.locator('body').innerText()).includes('Consulta de TASKs e versões'));
        assert((await page.locator('body').innerText()).includes('Homologação: v1.112.0-beta.198'));
        const notLinked = await context.request.get(endpoint + '?ticket=' + seed.ticket + '&wp=93004');
        assert.equal(notLinked.status(), 404);
        const otherEntity = await context.request.get(endpoint + '?ticket=' + legacy.tickets.other_entity + '&wp=91007');
        assert.equal(otherEntity.status(), 403, 'Entity scope');
        // An authorized internal user can query; replaying the final step is idempotent.
        const internal = await browser.newContext(); const internalPage = await internal.newPage();
        await login(internalPage, 'internal');
        await internalPage.goto(ticketUrl);
        await internalPage.locator('.demandas-publications-refresh').waitFor({ state: 'attached' });
        let csrf = await internalPage.locator('.demandas-publications-refresh').getAttribute('data-csrf');
        const step = async (action, job = '') => {
            const response = await internal.request.post(endpoint, { form: { ticket: seed.ticket, wp: 93001, action, job, _glpi_csrf_token: csrf } });
            assert.equal(response.status(), 200);
            const result = await response.json(); csrf = result.csrf; return result;
        };
        let progress = await step('start');
        while (!progress.done) progress = await step('step', progress.job);
        const completed = fixture({ mode: 'snapshot', ticket: seed.ticket });
        await step('step', progress.job);
        assert.deepEqual(fixture({ mode: 'snapshot', ticket: seed.ticket }), completed, 'Repeated completion');
        await internal.close();
        for (const role of ['readonly', 'client']) {
            const other = await browser.newContext(); const p = await other.newPage();
            await login(p, role);
            const read = await other.request.get(endpoint + '?ticket=' + seed.ticket + '&wp=93001');
            assert.equal(read.status(), role === 'readonly' ? 200 : 403);
            if (role === 'readonly') {
                await p.goto(ticketUrl);
                await p.locator('.demandas-publications-open').waitFor();
                assert.equal(await p.locator('.demandas-publications-refresh').count(), 0);
            }
            // Valid session does not grant refresh privileges.
            const html = await (await other.request.get(base + '/front/preference.php')).text();
            const token = html.match(/name=["']_glpi_csrf_token["'][^>]*value=["']([^"']+)/)?.[1] || '';
            assert(token, 'Missing CSRF token for authorization test');
            const write = await other.request.post(endpoint, { form: { ticket: seed.ticket, wp: 93001, action: 'start', _glpi_csrf_token: token } });
            assert.equal(write.status(), 403);
            await other.close();
        }
        await page.setViewportSize({ width: 1440, height: 1100 });
        await page.goto(base + '/plugins/demandas/front/dashboard.php');
        const donut = await page.locator('.demandas-donut').boundingBox();
        const legend = await page.locator('.demandas-legend').boundingBox();
        assert(legend.y >= donut.y + donut.height, 'Legend must be below chart');
        await page.screenshot({ path: path.join(out, 'dashboard-028.png'), fullPage: true });
        assert.deepEqual(errors, []);
        assert.equal(fixture({ mode: 'sync', ticket: seed.ticket }).ok, true);
        console.log('PASS: UI, nomes legados, paginação, PR/beta/produção, CSRF, perfis, falhas preservam cache, responsividade e legenda.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
