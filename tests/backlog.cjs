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
    const password=randomBytes(24).toString('hex'); const {users,tickets}=fixture({password});
    const browser=await chromium.launch({channel:'msedge',headless:true});
    try {
        for (const role of ['admin','internal','readonly','client']) {
            const context=await browser.newContext({viewport:{width:1366,height:768}}); const page=await context.newPage();
            let releaseHours;
            const hoursGate = new Promise(resolve => { releaseHours = resolve; });
            await page.route('**/backlog-data.php?action=times&*', async route => {await hoursGate; await route.continue();});
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
            assert.match(await page.locator('.backlog-time').first().innerText(),/Consultando/, 'Cards disponíveis antes das horas');
            releaseHours();
            await page.waitForFunction(()=>document.querySelector('.backlog-time')?.textContent.includes('3h'));
            assert(await page.evaluate(()=>{
                const column=document.querySelector('.backlog-column'), cards=column.querySelector('.backlog-column-cards');
                column.style.height='150px';
                const titleTop=column.querySelector('h2').getBoundingClientRect().top;
                cards.scrollTop=100;
                const ok=cards.scrollTop>0 && column.querySelector('h2').getBoundingClientRect().top===titleTop;
                column.style.height=''; return ok;
            }), 'Rolagem interna preserva cabeçalho');
            assert.match(await page.locator('.backlog-card').first().innerText(),/3h 00min/,'Soma pessoal paginada sem horas de outro usuário');
            assert.equal(await page.locator('#backlog-board script').count(),0);
            await page.locator('.backlog-card').first().click(); await page.locator('#backlog-detail.show').waitFor();
            await page.locator('#backlog-description table').waitFor();
            assert.equal(await page.locator('#backlog-description img').count(),1);
            await page.waitForFunction(()=>document.querySelector('#backlog-description img')?.naturalWidth>0);
            assert.equal(await page.locator('#backlog-description script, #backlog-description [onerror]').count(),0);
            assert.match(await page.locator('.backlog-card').first().innerText(),new RegExp(String(tickets[0])));
            assert(!(await page.locator('#backlog-board').innerText()).includes(String(tickets[99999])));
            assert.equal(await page.locator('.backlog-card a').first().getAttribute('target'),'_blank');
            assert.match(await page.locator('#backlog-openproject').getAttribute('href'),/work_packages\/111$/);
            await page.locator('#backlog-detail .btn-close').click();
            await page.locator('#backlog-search').fill(String(tickets[0]));
            assert.equal(await page.locator('.backlog-card').count(),1);
            await page.locator('#backlog-search').fill('sem-resultado-qa');
            assert.equal(await page.locator('.backlog-card').count(),0);
            await page.locator('#backlog-search').fill('');
            assert.equal(await page.locator('.backlog-card').count(),3);
            if (role==='admin') {
                await page.locator('#backlog-people').click();
                await page.locator('#backlog-person option[value="8"]').waitFor({state:'attached'});
                await page.locator('#backlog-person').selectOption('8');
                await page.waitForFunction(()=>document.querySelector('.backlog-number')?.textContent==='#211');
                await page.locator('.backlog-card').first().click();
                await page.locator('#backlog-description table').waitFor();
                await page.locator('#backlog-detail .btn-close').click();
                await page.locator('#backlog-person').selectOption('0');
                await page.waitForFunction(()=>document.querySelector('.backlog-number')?.textContent==='#111');
            } else {
                assert.equal(await page.locator('#backlog-person').count(),0);
                for(const query of ['responsible=8','action=people','action=detail&wp=211','action=image&wp=211&attachment=1']) {
                    assert.equal((await context.request.get(base+'/plugins/demandas/front/backlog-data.php?'+query)).status(),403);
                }
            }
            assert.equal((await context.request.get(base+'/plugins/demandas/front/backlog-data.php?action=image&wp=111&attachment=999')).status(),403);
            assert.equal((await context.request.get(base+'/plugins/demandas/front/backlog-data.php?action=detail&wp=999')).status(),502);
            assert.equal((await context.request.get(base+'/plugins/demandas/front/backlog-data.php?action=times&ids[]=1')).status(),400);
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
                for(const token of ['qa-time403','qa-401','qa-403','qa-500','qa-schema']) {
                    fixture({user:users[role].id,token});await page.reload();
                    await page.waitForFunction(()=>!document.querySelector('#backlog-refresh').disabled);
                    if(token==='qa-time403') { await page.waitForFunction(()=>document.querySelector('.backlog-time')?.textContent.includes('Indisponível')); assert.match(await page.locator('.backlog-card').innerText(),/Indisponível/); }
                    else if(token==='qa-schema') assert.match(await page.locator('.backlog-card').innerText(),/Cliente fictício/);
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
                    if (!process.env.SKIP_SCREENSHOTS) await page.screenshot({path:require('node:path').join(require('node:os').tmpdir(),`backlog-${role}-${width}-${dark}.png`)});
                }
            }
            assert.deepEqual(errors,[]); console.log('PASS '+role+': quadro, painel, permissões, preferências e isolamento');await context.close();
        }
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
