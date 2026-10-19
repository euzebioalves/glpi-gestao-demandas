const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync} = require('node:child_process');
const {randomBytes} = require('node:crypto');
const assert = require('node:assert/strict');
const base = 'http://127.0.0.1:8386';
const prefix = '/plugins/demandas/front/';
function fixture(input) {
    const result = spawnSync('docker', ['exec', '-i', 'demandas-test-021-glpi', 'php', '/tmp/legacy-reconciliation-fixture.php'], {input:JSON.stringify(input), encoding:'utf8'});
    assert.equal(result.status, 0, result.stderr + result.stdout);
    return JSON.parse(result.stdout);
}
(async () => {
    const password = randomBytes(24).toString('hex');
    const seed = fixture({mode:'seed', password});
    fixture({mode:'ui-rights'});
    const browser = await chromium.launch({channel:'msedge', headless:true});
    try {
        for (const role of ['admin', 'internal', 'client', 'readonly']) {
            const context = await browser.newContext();
            const page = await context.newPage();
            await page.goto(base);
            await page.locator('[name=login_name]').fill('reconciliation-' + role);
            await page.locator('[name=login_password]').fill(password);
            await Promise.all([page.waitForNavigation(), page.locator('[name=submit]').click()]);
            const canPublic = role !== 'readonly', canTechnical = ['admin','internal'].includes(role);
            const capabilities = await context.request.get(base + prefix + 'ui-permissions.php');
            assert.equal(capabilities.status(), 200);
            assert.match(capabilities.headers()['cache-control'], /no-store/);
            assert.deepEqual(await capabilities.json(), {view_public:canPublic,view_technical:canTechnical});
            const requests = [];
            page.on('request', r => { if (r.url().includes(prefix)) requests.push(r.url()); });
            // Use the real script and real session/endpoints in a small ticket shell.
            // The shell guarantees the fallback and header are exercised for both interfaces.
            await page.route('**/front/ticket.form.php?*', route => route.fulfill({contentType:'text/html',body:`<html><body><form role="search"><input id="global-search"></form><script src="/plugins/demandas/js/demandas.js"></script></body></html>`}));
            await page.goto(base + '/front/ticket.form.php?id=' + seed.tickets.open);
            await page.waitForLoadState('networkidle');
            assert.equal(requests.filter(u=>u.includes('ui-permissions.php')).length,1);
            assert.equal(requests.some(u=>u.includes('public-phase.php')),canPublic);
            assert.equal(requests.some(u=>u.includes('work-package-notification-feed.php')),canTechnical);
            assert.equal(await page.locator('#demandas-wp-alerts').count(),canTechnical ? 1 : 0);
            // Direct requests must still be denied, independently of the browser gate.
            if (!canTechnical) assert.equal((await context.request.get(base+prefix+'work-package-notification-feed.php')).status(),403);
            if (!canPublic) assert.equal((await context.request.get(base+prefix+'public-phase.php?tickets_id='+seed.tickets.open)).status(),403);
            if (role === 'client') {
                const phase = await context.request.get(base+prefix+'public-phase.php?tickets_id='+seed.tickets.open);
                assert.equal(phase.status(),200);
                assert(!Object.hasOwn(await phase.json(),'work_package_id'));
            }
            requests.length = 0;
            await page.goto(base + '/front/preference.php?id=' + seed.tickets.open);
            await page.waitForLoadState('networkidle');
            assert(!requests.some(u=>u.includes('public-phase.php')), 'Unrelated id must not be treated as a ticket');
            // Permission lookup failures must fail closed, without protected requests.
            for (const status of [401,403,503]) {
                await page.route('**/ui-permissions.php', route=>route.fulfill({status,body:'{}'}));
                requests.length = 0;
                await page.goto(base + '/front/ticket.form.php?id=' + seed.tickets.open);
                await page.waitForLoadState('networkidle');
                assert(!requests.some(u=>/public-phase.php|work-package-notification-feed.php/.test(u)));
                await page.unroute('**/ui-permissions.php');
            }
            await context.close();
            console.log('PASS: ' + role + ', calls, backend denials and lookup failures');
        }
        const anonymous = await browser.newContext();
        const response = await anonymous.request.get(base+prefix+'ui-permissions.php', {maxRedirects:0});
        assert.notEqual(response.status(),200);
        await anonymous.close();
    } finally { await browser.close(); }
})().catch(error=>{console.error(error);process.exitCode=1;});
