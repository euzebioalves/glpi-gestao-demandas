const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
const base='http://127.0.0.1:8386';
function fixture(input) {
    const r=spawnSync('docker',['exec','-i','demandas-test-021-glpi','php','/tmp/backlog-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'});
    assert.equal(r.status,0,r.stdout+r.stderr); return JSON.parse(r.stdout);
}
(async()=>{
    const password=randomBytes(24).toString('hex'); const {users}=fixture({password});
    const browser=await chromium.launch({channel:'msedge',headless:true});
    try {
        for (const role of ['admin','internal','readonly','client']) {
            const context=await browser.newContext({viewport:{width:1366,height:768}}); const page=await context.newPage();
            const errors=[]; page.on('pageerror',e=>errors.push(e.message));
            await page.goto(base); await page.locator('[name=login_name]').fill(users[role].login);await page.locator('[name=login_password]').fill(password);
            await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);
            const response=await page.goto(base+'/plugins/demandas/front/backlog.php');
            if(role==='client') {
                assert.equal(response.status(),403); assert.equal((await context.request.get(base+'/plugins/demandas/front/backlog-data.php')).status(),403);
                await context.close(); console.log('PASS cliente bloqueado');continue;
            }
            assert.equal(response.status(),200);
            await page.locator('.backlog-card').first().waitFor();
            assert.equal(await page.locator('.backlog-card').count(),3,'Paginação das WPs');
            assert.match(await page.locator('.backlog-card').first().innerText(),/3h 00min/,'Soma pessoal paginada sem horas de outro usuário');
            assert.equal(await page.locator('#backlog-board script').count(),0);
            await page.locator('.backlog-card').first().click(); await page.locator('#backlog-detail.show').waitFor();
            assert.equal(await page.locator('#backlog-description img').count(),0);
            assert.match(await page.locator('#backlog-openproject').getAttribute('href'),/work_packages\/111$/);
            await page.locator('#backlog-detail .btn-close').click();
            if(role==='readonly') {
                assert.equal(await page.locator('#backlog-configure').count(),0);
                const token=await page.locator('input[name=_glpi_csrf_token]').first().getAttribute('value');
                assert.equal((await context.request.post(base+'/plugins/demandas/front/backlog-data.php',{form:{_glpi_csrf_token:token,'statuses[]':'14'}})).status(),403);
            } else {
                await page.locator('#backlog-configure').click();
                await page.locator('[name="statuses[]"][value="12"]').uncheck();
                await page.locator('[name="statuses[]"][value="13"]').uncheck();
                await page.getByRole('button',{name:'Salvar colunas',exact:true}).click();
                await page.waitForFunction(()=>document.querySelectorAll('.backlog-card').length===1);
                await page.reload(); await page.locator('.backlog-card').first().waitFor();assert.equal(await page.locator('.backlog-card').count(),1);
                await page.locator('#backlog-refresh').click();await page.locator('.backlog-card').first().waitFor();assert.equal(await page.locator('.backlog-card').count(),1);
                const csrf=await context.request.post(base+'/plugins/demandas/front/backlog-data.php',{form:{'statuses[]':'14'}});assert.equal(csrf.status(),403);
                for(const token of ['qa-time403','qa-401','qa-403','qa-500']) {
                    fixture({user:users[role].id,token});await page.reload();
                    await page.waitForFunction(()=>!document.querySelector('#backlog-refresh').disabled);
                    if(token==='qa-time403') assert.match(await page.locator('.backlog-card').innerText(),/Indisponível/);
                    else assert.equal(await page.locator('.backlog-card').count(),0);
                }
                fixture({user:users[role].id,token:'qa-personal'});await page.reload();await page.locator('.backlog-card').first().waitFor();assert.equal(await page.locator('.backlog-card').count(),1);
                await page.locator('#backlog-configure').click();
                await page.locator('[name="statuses[]"][value="11"]').uncheck();
                await page.getByRole('button',{name:'Salvar colunas',exact:true}).click();
                await page.getByText('Nenhuma coluna selecionada.',{exact:true}).waitFor();
                await page.reload();await page.getByText('Nenhuma coluna selecionada.',{exact:true}).waitFor();
                await page.locator('#backlog-configure').click();
                await page.locator('[name="statuses[]"][value="14"]').check();
                await page.getByRole('button',{name:'Salvar colunas',exact:true}).click();
                await page.locator('.backlog-card').first().waitFor();
                assert.match(await page.locator('.backlog-column-title').innerText(),/Fechado/);
            }
            for(const width of [1366,390]) {
                await page.setViewportSize({width,height:768});
                for(const dark of [false,true]) {
                    await page.evaluate(d=>{document.documentElement.dataset.glpiTheme=d?'auror_dark':'auror';document.documentElement.dataset.glpiThemeDark=d?'1':'0';},dark);
                    await page.screenshot({path:require('node:path').join(require('node:os').tmpdir(),`backlog-${role}-${width}-${dark}.png`)});
                }
            }
            assert.deepEqual(errors,[]); console.log('PASS '+role+': quadro, painel, permissões, preferências e isolamento');await context.close();
        }
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
