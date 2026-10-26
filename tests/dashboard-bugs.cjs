// Run only against the disposable GLPI 11.0.9 instance, never shared data.
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const { spawnSync } = require('node:child_process');
const assert = require('node:assert/strict');
const crypto = require('node:crypto');
const path = require('node:path');
const fs = require('node:fs');
const base = process.env.DASHBOARD_TEST_URL || 'http://127.0.0.1:8192';
const container = process.env.DASHBOARD_TEST_CONTAINER || 'demandas-dashboard029-glpi';
if (!/^http:\/\/127\.0\.0\.1:819[23]$/.test(base) || !/^demandas-dashboard(029|031)-glpi$/.test(container)) throw new Error('Somente instância descartável do dashboard.');
function fixture(input, legacy = false) {
    const r = spawnSync('docker', ['exec','-i',container,'php','/tmp/' + (legacy ? 'legacy-reconciliation' : 'dashboard-bugs') + '-fixture.php'], {input:JSON.stringify(input),encoding:'utf8'});
    if (r.status !== 0) throw new Error(r.stderr || r.stdout);
    return JSON.parse(r.stdout);
}
(async()=>{
    const password=crypto.randomBytes(24).toString('hex');
    const accounts=fixture({mode:'seed',password},true);
    const seed=fixture({mode:'seed'});
    const before=fixture({mode:'snapshot'});
    const browser=await chromium.launch({channel:'msedge',headless:true});
    const endpoint=base+'/plugins/demandas/front/dashboard.php';
    const exportUrl=base+'/plugins/demandas/front/dashboard-export.php';
    const out=path.join(__dirname,'../docs/assets/manual'); fs.mkdirSync(out,{recursive:true});
    async function login(role) {
        const ctx=await browser.newContext({viewport:{width:1440,height:1100}});
        const p=await ctx.newPage(); await p.goto(base);
        await p.locator('[name="login_name"]').fill('reconciliation-'+role);
        await p.locator('[name="login_password"]').fill(password);
        await Promise.all([p.waitForURL(u=>/\/(front\/central\.php|servicecatalog|helpdesk)/i.test(u.pathname)),p.locator('[name="submit"]').click()]);
        return {ctx,p};
    }
    try {
        const {ctx,p}=await login('admin');
        const errors=[]; p.on('pageerror',e=>errors.push(e.message));
        let response=await p.goto(endpoint+'?view=bugs&q=QA029'); assert.equal(response.status(),200);
        assert.equal(await p.locator('.demandas-table-card tbody tr').count(),6,'Unique open bugs/improvements only');
        assert.match(await p.locator('[aria-label="Entregas com chamado aberto"]').innerText(),/3\s+Com alguma entrega/);
        assert.match(await p.locator('[aria-label="Entregas com chamado aberto"]').innerText(),/1\s+Todas as WPs em Entregue/);
        assert.match(await p.locator('[aria-label="Entregas com chamado aberto"]').innerText(),/2\s+Entrega parcial/);
        assert.equal(await p.locator('.demandas-wp-statuses script').count(),0);
        assert.match(await p.locator('body').innerText(),/Dados desatualizados/);
        assert.equal(await p.locator('details[open]').count(),0,'Filters, preferences and WP details start collapsed');
        assert(await p.locator('.da-action').count()>0);
        assert(await p.locator('.da-action').evaluateAll(nodes=>nodes.every(n=>Number(n.querySelector('strong').textContent)>0)));
        const firstDetails=p.locator('.da-queue .da-wps').first();
        await firstDetails.locator('summary').click();
        assert(await firstDetails.locator('.da-wp-list').isVisible());
        assert.equal(await firstDetails.locator('.da-wp-list script').count(),0);
        await firstDetails.locator('summary').click();
        await p.locator('.demandas-filter-summary').click();
        assert(await p.locator('[name="classification"]').isVisible());
        await p.locator('.demandas-filter-summary').click();
        await p.goto(endpoint+'?view=bugs&q=QA029&op_status=Entregue&delivery=partial');
        assert.equal(await p.locator('.demandas-table-card tbody tr').count(),2);
        assert.match(await p.locator('.demandas-table-card').innerText(),/Novo/);
        assert.match(await p.locator('.demandas-table-card').innerText(),/Status não informado/);
        await p.goto(endpoint+'?view=bugs&q=QA029&statuses_set=1&statuses[]=2');
        assert.equal(await p.locator('.demandas-table-card tbody tr').count(),1);
        assert.match(await p.locator('.demandas-table-card').innerText(),/QA029 parcial/);
        await p.goto(endpoint+'?view=bugs&statuses_set=1');
        assert.match(await p.locator('.demandas-table-card').innerText(),/Nenhum chamado/);
        await p.goto(endpoint+'?view=bugs&q=QA029&ticket_scope=all');
        assert.equal(await p.locator('.demandas-table-card tbody tr').count(),8);
        await p.goto(endpoint+'?view=bugs&q=QA029&ticket_scope=all&attention=closed_pending');
        assert.equal(await p.locator('.demandas-table-card tbody tr').count(),1);
        assert.match(await p.locator('.demandas-table-card').innerText(),/QA029 fechado/);
        for (const filter of ['attention=aging','op_owner=Ana%20QA','glpi_owner='+seed.owner]) {
            await p.goto(endpoint+'?view=bugs&q=QA029&'+filter);
            assert.equal(await p.locator('.demandas-table-card tbody tr').count(),1,filter);
            assert.match(await p.locator('.demandas-table-card').innerText(),/QA029 parcial/);
            await p.locator('.demandas-table-card .da-wps summary').first().click();
            assert.match(await p.locator('.demandas-table-card').innerText(),/20 dias observados/);
        }
        await p.goto(endpoint+'?view=bugs&q=QA029&attention=aging&attention_days=21');
        assert.match(await p.locator('.demandas-table-card').innerText(),/Nenhum chamado/);
        await p.goto(endpoint+'?view=bugs&q=QA029');
        await p.getByRole('link',{name:/Vincular WP/}).first().click();
        assert.match(await p.locator('.demandas-table-card').innerText(),/QA029 sem-wp/);
        await p.goto(endpoint+'?view=bugs&q=QA029');
        for (const width of [1440,390]) {
            await p.setViewportSize({width,height:width===390?844:1100});
            await p.goto(endpoint+'?view=bugs&q=QA029');
            if(width===390) assert(await p.locator('.demandas-action-view').evaluate(n=>n.getBoundingClientRect().width>=innerWidth*.85),'Mobile content must use the viewport');
            for (const dark of [false,true]) {
                await p.evaluate(dark=>{
                    document.documentElement.setAttribute('data-glpi-theme',dark?'auror_dark':'auror');
                    document.documentElement.setAttribute('data-glpi-theme-dark',dark?'1':'0');
                },dark);
                // WCAG AA for the new text, semantic cards, actions and summaries.
                const contrast=await p.evaluate(()=>{
                    const lum=c=>{
                        const rgb=c.match(/[\d.]+/g).slice(0,3).map(Number).map(v=>{v/=255;return v<=.04045?v/12.92:((v+.055)/1.055)**2.4});
                        return .2126*rgb[0]+.7152*rgb[1]+.0722*rgb[2];
                    };
                    return [...document.querySelectorAll('.da-action h3,.da-action p,.da-action-link,.da-tag,.da-stage,.da-section-head p,.demandas-action-view .nav-link.active,.demandas-action-view .btn-primary,.demandas-action-view .btn-outline-primary')]
                      .filter(n=>n.getClientRects().length).map(n=>{
                        let parent=n,bg;
                        while(parent){bg=getComputedStyle(parent).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)'&&bg!=='transparent')break;parent=parent.parentElement}
                        const a=lum(getComputedStyle(n).color),b=lum(bg);
                        return {text:n.textContent.slice(0,50),ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05)};
                    }).filter(x=>x.ratio<4.5);
                });
                assert.deepEqual(contrast,[], 'Text contrast below 4.5:1');
                await p.screenshot({path:path.join(out,`dashboard-bugs-${width}-${dark?'dark':'light'}.png`),fullPage:true});
                if(width===1440) await p.screenshot({path:path.join(out,`dashboard-actions-${dark?'dark':'light'}.png`)});
                if(width===390) await p.screenshot({path:path.join(out,`dashboard-actions-mobile-${dark?'dark':'light'}.png`)});
                if(width===1440&&!dark) {
                    await p.locator('.da-queue').screenshot({path:path.join(out,'dashboard-action-queue.png')});
                    await p.locator('[aria-label="Envelhecimento por etapa"]').screenshot({path:path.join(out,'dashboard-action-stages.png')});
                    assert(await p.locator('.da-queue tbody tr').evaluateAll(rows=>rows.every(r=>r.getBoundingClientRect().height<230)),'Collapsed queue rows should stay compact');
                }
                assert(await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Horizontal overflow');
            }
        }
        await p.setViewportSize({width:1440,height:1100});
        await p.locator('.da-options > summary').click();
        await p.locator('.da-preferences summary').click();
        await p.locator('[name="default_view"]').selectOption('bugs');
        await Promise.all([p.waitForURL(u=>u.search==='?view=bugs'),p.getByRole('button',{name:'Salvar visão padrão'}).click()]);
        assert.equal(fixture({mode:'preferences'}).length,1);
        assert.equal(Number(fixture({mode:'preferences'})[0].users_id),accounts.users.admin);
        await p.goto(endpoint); assert.equal(await p.locator('.nav-tabs .active').innerText(),'Bugs e Melhorias');
        assert.equal((await ctx.request.post(endpoint,{form:{default_view:'general'}})).status(),403,'CSRF');
        await p.goto(endpoint+'?view=general');
        assert.equal(await p.locator('details[open]').count(),0,'General filters remain collapsed');
        const csrf=await p.locator('[name="_glpi_csrf_token"]').first().inputValue();
        await ctx.request.post(endpoint,{form:{default_view:'bugs',users_id:accounts.users.client,_glpi_csrf_token:csrf}});
        assert.equal(fixture({mode:'preferences'}).length,1,'Cannot set another user preference');
        for (const format of ['pdf','xlsx']) {
            const exported=await ctx.request.get(exportUrl+'?view=bugs&q=QA029&delivery=partial&format='+format);
            assert.equal(exported.status(),200,format);
            const bytes=await exported.body(); assert(bytes.length>1000);
            assert.equal(bytes.subarray(0,format==='pdf'?4:2).toString(),format==='pdf'?'%PDF':'PK');
            if (format==='xlsx') {
                const cells=fixture({mode:'read-export',data:bytes.toString('base64')});
                assert.equal(cells.length,3,'Header and two filtered tickets');
                assert.deepEqual(cells.slice(1).map(row=>Number(row[0])).sort(),[seed.tickets.parcial,seed.tickets.desconhecida].sort());
                assert(cells.slice(1).some(row=>row[9].includes('Entregue')&&row[9].includes('Novo')));
                assert(cells.slice(1).every(row=>row[13].includes('Entrega parcial')));
                assert(cells.slice(1).some(row=>String(row[15]??'').includes('Ana QA')&&String(row[17]??'').includes('20 dias')));
            }
        }
        await p.goto(base+'/front/ticket.form.php?id='+seed.tickets.parcial);
        await p.locator('.demandas-ticket-wp-alert').waitFor();
        assert.match(await p.locator('.demandas-ticket-wp-alert').innerText(),/Entregue/);
        assert.match(await p.locator('.demandas-ticket-wp-alert').innerText(),/Novo/);
        await p.locator('.demandas-ticket-wp-alert').scrollIntoViewIfNeeded();
        await p.screenshot({path:path.join(out,'dashboard-ticket-alert.png')});
        assert.equal((await ctx.request.get(base+'/front/ticket.form.php?id='+seed.tickets['outra-entidade'])).status(),403);
        for (const role of ['internal','readonly','client']) {
            const {ctx:other,p:page}=await login(role);
            const allowed=role==='internal';
            assert.equal((await page.goto(endpoint+'?view=bugs')).status(),allowed?200:403,role);
            assert.equal((await other.request.get(endpoint+'?view=bugs&delivery=any')).status(),403,role+' review');
            assert.equal((await other.request.get(exportUrl+'?view=bugs&delivery=any&format=xlsx')).status(),403);
            for (const reason of ['partial','all_delivered','closed_pending']) {
                assert.equal((await other.request.get(endpoint+'?view=bugs&attention='+reason)).status(),403);
                assert.equal((await other.request.get(exportUrl+'?view=bugs&attention='+reason+'&format=xlsx')).status(),403);
            }
            if (allowed) {
                assert.equal(await page.locator('[name="default_view"]').count(),0);
                assert.equal(await page.locator('[aria-label="Entregas com chamado aberto"]').count(),0);
            }
            await page.goto(base+'/front/preference.php');
            const token=await page.locator('[name="_glpi_csrf_token"]').first().inputValue();
            assert.equal((await other.request.post(endpoint,{form:{default_view:'general',_glpi_csrf_token:token}})).status(),403,role+' preference write');
            await page.goto(base+'/front/ticket.form.php?id='+seed.tickets.parcial);
            assert.equal(await page.locator('.demandas-ticket-wp-alert').count(),0);
            await other.close();
        }
        fixture({mode:'revoke'});
        const revoked=await login('admin');
        await revoked.p.goto(endpoint);
        assert.equal(await revoked.p.locator('.nav-tabs .active').innerText(),'Visão geral','Default revalidated');
        assert.equal((await revoked.ctx.request.get(endpoint+'?view=bugs')).status(),403,'Technical access prerequisite');
        await revoked.p.goto(base+'/front/ticket.form.php?id='+seed.tickets.parcial);
        assert.equal(await revoked.p.locator('.demandas-ticket-wp-alert').count(),0,'Alert requires technical right too');
        await revoked.ctx.close();
        assert.deepEqual(fixture({mode:'snapshot'}),before,'Read-only views do not change tickets, WPs or followups');
        assert.deepEqual(errors,[]);
        assert.equal(fixture({mode:'upgrade'},true).ok,true);
        assert.equal(fixture({mode:'webhook'},true).ok,true);
        console.log('PASS: escopo, totais únicos, todas WPs, Entregue exclusivo, filtros, perfis, entidade, CSRF, preferência, PDF/XLSX, alertas, responsividade, migração e webhook.');
    } finally { await browser.close(); }
})().catch(e=>{console.error(e);process.exitCode=1;});
