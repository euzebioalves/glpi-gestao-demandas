const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
function fixture(file,input){const r=spawnSync('docker',['exec','-i','demandas-test-021-glpi','php','/tmp/'+file+'.php'],{input:JSON.stringify(input),encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);return JSON.parse(r.stdout);}
const base='http://127.0.0.1:8386', pagePath='/plugins/demandas/front/legacy-reconciliation.php', formPath='/plugins/demandas/front/legacy-reconciliation.form.php';
(async()=>{
 const password=randomBytes(24).toString('hex'); fixture('legacy-reconciliation-fixture',{mode:'seed',password});
 const {tickets,wp}=fixture('reconciliation-many-fixture',{mode:'seed'});
 const snapshot=()=>fixture('reconciliation-many-fixture',{mode:'snapshot',wp});
 const before=snapshot();
 const browser=await chromium.launch({channel:'msedge',headless:true});
 try {
  for(const role of ['internal','readonly','client','admin']) {
   const context=await browser.newContext({viewport:{width:1366,height:900}});const page=await context.newPage(); const errors=[];page.on('pageerror',e=>errors.push(e.message));
   await page.goto(base);await page.locator('[name=login_name]').fill('reconciliation-'+role);await page.locator('[name=login_password]').fill(password);await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);
   const res=await page.goto(base+pagePath+'?ticket_scope=all&ticket='+tickets.second+'&origin_ticket='+tickets.second);
   if(role==='client') {assert.equal(res.status(),403);await context.close();continue;}
   assert.equal(res.status(),200);
   const token=()=>page.locator('form[action="'+formPath+'"] input[name=_glpi_csrf_token]').inputValue();
   async function post(pair,reason,withToken=true) {
    const data={'pairs[]':pair,confirm:'1',ticket_scope:'all',origin_ticket:String(tickets.second),['reasons['+pair+']']:reason};
    if(withToken)data._glpi_csrf_token=await token();
    return context.request.post(base+formPath,{form:data,maxRedirects:0});
   }
   if(role!=='admin') {
    assert.equal(await page.locator('input[name="pairs[]"]').count(),0);
    assert.equal((await post(tickets.second+':'+wp,'Decisão autorizada em teste')).status(),403);
    assert.deepEqual(snapshot(),before);await context.close();continue;
   }
   assert.equal((await post(tickets.second+':'+wp,'',false)).status(),403);
   await post(tickets.second+':'+wp,'');assert.deepEqual(snapshot(),before);
   await page.reload();
   await page.locator('.legacy-conflict-details').click();await page.locator('#legacyConflictDetails.show').waitFor();
   assert.match(await page.locator('#legacy-conflict-content').innerText(),/vínculos adicionais efetivamente conciliados/);
   await page.locator('#legacyConflictDetails .btn-close').click();
   await page.locator('input[name="pairs[]"]').check();
   await page.locator('textarea[name^="reasons"]').fill('Mesma entrega atende dois chamados. Justificativa QA <b>texto</b>.');
   await page.locator('input[name=confirm]').check();
   await Promise.all([page.waitForURL('**/front/ticket.form.php?**'),page.getByRole('button',{name:'Validar e conciliar selecionadas'}).click()]);
   assert.equal(new URL(page.url()).searchParams.get('id'),String(tickets.second));
   assert.equal(new URL(page.url()).searchParams.get('forcetab'),'GlpiPlugin\\Demandas\\TicketDemand$1');
   let state=snapshot();assert.equal(state.links.length,2);assert.deepEqual(state.links.find(l=>Number(l.tickets_id)===tickets.first),before.links[0]);assert.deepEqual(state.time_entries,before.time_entries);
   assert.match(JSON.parse(state.events[0].details_json).reconciliation_reason,/Justificativa QA/);
   await page.goto(base+pagePath+'?ticket_scope=all&ticket='+tickets.second);await post(tickets.second+':'+wp,'Repetição não deve criar nada');assert.deepEqual(snapshot(),state);
   await page.goto(base+pagePath+'?ticket_scope=all&ticket='+tickets.multiple+'&origin_ticket='+tickets.multiple);
   assert.equal(await page.locator('input[name="pairs[]"]').count(),2);
   for(const check of await page.locator('input[name="pairs[]"]').all())await check.check();
   for(const reason of await page.locator('textarea').all())await reason.fill('Este chamado demanda as duas Work Packages existentes.');
   await page.locator('input[name=confirm]').check();
   await Promise.all([page.waitForURL('**/front/ticket.form.php?**'),page.getByRole('button',{name:'Validar e conciliar selecionadas'}).click()]);
   state=snapshot();assert.equal(state.links.length,4);assert.equal(state.links.filter(l=>Number(l.tickets_id)===tickets.multiple).length,2);
   await page.goto(base+pagePath+'?ticket_scope=all&ticket='+tickets.normal);
   const normal=await context.request.post(base+formPath,{form:{_glpi_csrf_token:await token(),'pairs[]':tickets.normal+':'+(wp+2),confirm:'1'},maxRedirects:0});assert.match(normal.headers().location,/legacy-reconciliation.php/);
   assert.equal(snapshot().links.length,5);
   await page.goto(base+pagePath+'?ticket_scope=all&ticket='+tickets.multiple);assert.equal(await page.locator('input[name="pairs[]"]').count(),0);assert.match(await page.locator('table.table-vcenter').innerText(),/Já conciliada/);
   for(const width of [1366,390]) {
    await page.setViewportSize({width,height:900});
    for(const dark of [false,true]) {
     await page.evaluate(d=>{document.documentElement.dataset.glpiTheme=d?'auror_dark':'auror';document.documentElement.dataset.glpiThemeDark=d?'1':'0';},dark);
     await page.screenshot({path:require('node:path').join(require('node:os').tmpdir(),`reconciliation-many-${width}-${dark}.png`),fullPage:true});
    }
   }
   assert.deepEqual(errors,[]);await context.close();
  }
  const sync=fixture('reconciliation-many-fixture',{mode:'sync',wp});assert.equal(sync.links,3);assert.equal(sync.followups,3);
  console.log('PASS: retorno à aba, múltiplos chamados/WPs, justificativa, histórico/tempo preservados, repetição, perfis, CSRF e webhook em todos os vínculos sem duplicar acompanhamentos.');
 } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});

