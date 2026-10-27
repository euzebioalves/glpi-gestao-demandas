const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
const base='http://127.0.0.1:8386';
function fixture(name,input){const r=spawnSync('docker',['exec','-i','demandas-test-021-glpi','php','/tmp/'+name+'.php'],{input:JSON.stringify(input),encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);return JSON.parse(r.stdout);}
(async()=>{
 const password=randomBytes(24).toString('hex');
 const seed=fixture('security-stage1-fixture',{mode:'seed',password});fixture('indicators-http-fixture',{mode:'seed'});
 const browser=await chromium.launch({channel:'msedge',headless:true});
 const endpoint=base+'/plugins/demandas/front/operational-health.php';
 async function login(role){const context=await browser.newContext();const page=await context.newPage();await page.goto(base);await page.locator('[name=login_name]').fill('security-'+role);await page.locator('[name=login_password]').fill(password);await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);return {context,page};}
 async function post(c,p,form,csrf=true){await p.reload();const token=await p.locator('input[name=_glpi_csrf_token]').first().inputValue();return c.request.post(base+'/plugins/demandas/front/operational-health.form.php',{form:{...form,...(csrf?{_glpi_csrf_token:token}:{})},maxRedirects:0});}
 const state=()=>fixture('indicators-http-fixture',{mode:'snapshot'});
 try{
  const {context,page}=await login('internal');await page.goto(endpoint);
  assert.equal((await post(context,page,{action:'run'})).status(),302);
  let rows=state().rows;const row=rows.find(r=>r.rule_code==='wp_data_quality');assert(row);
  const id=row.id;await page.goto(endpoint+'?finding_id='+id);
  const before=state();await post(context,page,{action:'followup',finding_id:id,note:'Invalid CSRF'},false);assert.deepEqual(state(),before);
  await page.reload();await page.locator('#finding-note').fill('Conferir <script>window.qaInjected=1</script> com a equipe.');
  await page.locator('[name=take_ownership]').check();
  const due=new Date(Date.now()+86400000*2).toISOString().slice(0,10);await page.locator('#finding-due').fill(due);
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Salvar acompanhamento'}).click()]);
  let changed=state().rows.find(r=>r.id===id);assert.equal(Number(changed.owner_users_id),seed.users.internal);assert.equal(changed.due_date,due);
  await page.goto(endpoint+'?finding_id='+id);assert.equal(await page.evaluate(()=>window.qaInjected),undefined);
  await page.getByText('Histórico de tratativas (até 100 mais recentes)',{exact:true}).click();
  assert((await page.locator('#treatment').innerText()).includes('<script>window.qaInjected=1</script>'));
  await page.locator('#finding-note').fill('Aguardar avaliação com a equipe responsável.');
  await Promise.all([page.waitForNavigation(),page.getByRole('button',{name:'Adiar por sete dias'}).click()]);
  await post(context,page,{action:'run'});assert.equal(state().rows.find(r=>r.id===id).state,'ignored');
  await page.goto(endpoint+'?finding_id='+id);
  for(const theme of ['light','dark'])for(const width of [390,1366]){
   await page.setViewportSize({width,height:850});await page.reload();await page.locator('html').evaluate((el,t)=>{el.setAttribute('data-glpi-theme',t==='dark'?'auror_dark':'auror');el.setAttribute('data-glpi-theme-dark',t==='dark'?'1':'0');},theme);
   assert.equal(await page.getByRole('button',{name:'Salvar acompanhamento'}).isVisible(),true);
   assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+2),'Page overflow');
   await page.screenshot({path:'dist/indicators-'+theme+'-'+width+'.png',fullPage:true});
  }
  await context.close();
  const viewer=await login('viewer');assert.equal((await viewer.page.goto(endpoint+'?finding_id='+id)).status(),200);
  assert.equal(await viewer.page.getByRole('button',{name:'Salvar acompanhamento'}).count(),0);
  assert.equal((await post(viewer.context,viewer.page,{action:'followup',finding_id:id,note:'Denied'})).status(),403);await viewer.context.close();
  const client=await login('client');assert.equal((await client.context.request.get(endpoint+'?finding_id='+id)).status(),403);await client.context.close();
  const foreign=await login('foreign');assert.equal((await foreign.context.request.get(endpoint+'?finding_id='+id)).status(),403);await foreign.context.close();
  const admin=await login('admin');assert.equal((await admin.page.goto(endpoint+'?finding_id='+id)).status(),200);await admin.context.close();
  console.log('PASS: real HTTP/CSRF, treatment persistence, audit escaping, partial repeat, administrator/internal/viewer/client/foreign and responsive layouts');
 }finally{await browser.close();fixture('indicators-http-fixture',{mode:'cleanup'});}
})().catch(e=>{console.error(e);process.exitCode=1;});
