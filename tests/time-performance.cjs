const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
const base='http://127.0.0.1:8386', container='demandas-test-021-glpi';
function docker(args,input){const r=spawnSync('docker',['exec','-i',container,...args],{input,encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);return r.stdout;}
function fixture(name,input){return JSON.parse(docker(['php','/tmp/'+name+'.php'],JSON.stringify(input)));}
function mode(value){docker(['sh','-c','cat > /tmp/time-performance-mode'],value);}
(async()=>{
 const password=randomBytes(24).toString('hex');
 fixture('security-stage1-fixture',{mode:'seed',password});
 const entry=fixture('time-performance-fixture',{});
 const pid=docker(['sh','-c','php -S 127.0.0.1:8397 /tmp/time-performance-api.php >/tmp/time-performance-server.log 2>&1 & echo $!']).trim();
 assert(/^\d+$/.test(pid));
 const browser=await chromium.launch({channel:process.env.PLAYWRIGHT_CHANNEL||'msedge',headless:true});
 async function login(role){const context=await browser.newContext();const page=await context.newPage();await page.goto(base);await page.locator('[name=login_name]').fill('security-'+role);await page.locator('[name=login_password]').fill(password);await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);return {context,page};}
 try {
  const {context,page}=await login('admin');const errors=[];page.on('pageerror',e=>errors.push(e.message));
  const url=base+'/plugins/demandas/front/time-entry.php?date_from=2040-01-01&date_to=2040-01-01';
  mode('503');docker(['sh','-c',': > /tmp/time-performance-calls']);
  const started=Date.now();assert.equal((await page.goto(url)).status(),200);
  assert.equal(await page.locator('.te-edit').count(),50);
  assert((await page.locator('body').innerText()).includes('55h 00min'));
  await page.getByRole('link',{name:'Próxima',exact:true}).click();
  assert.equal(await page.locator('.te-edit').count(),5);
  assert((await page.locator('body').innerText()).includes('55h 00min'));
  assert.equal(docker(['cat','/tmp/time-performance-calls']),'');
  console.log('PASS: 55 entries paginated with full total and zero API calls during outage; two pages '+(Date.now()-started)+'ms');
  const report=base+'/plugins/demandas/front/time-entry-report.php?id='+entry.id;
  assert.equal((await context.request.get(report,{maxRedirects:0})).status(),502);
  mode('ok');const response=await context.request.get(report,{maxRedirects:0});assert.equal(response.status(),302);
  assert(response.headers().location.startsWith('https://openproject.example.invalid/projects/qa-project/cost_reports?'));
  for(const role of ['internal','client']){const other=await login(role);assert.equal((await other.context.request.get(report,{maxRedirects:0})).status(),403);await other.context.close();}
  for(const denied of ['401','403','422','503']) {mode(denied);const r=await context.request.get(base+'/plugins/demandas/front/time-entry-activities.php?wp=98001');assert.equal(r.status(),400);assert.equal((await r.json()).ok,false);}
  mode('ok');assert.equal((await (await context.request.get(base+'/plugins/demandas/front/time-entry-activities.php?wp=98001')).json()).activities.length,1);
  console.log('PASS: report on demand, owner/profile authorization and remote failures');
  // A remote request must still be pending when a second request in the same session completes.
  mode('slow');docker(['sh','-c',': > /tmp/time-performance-started']);
  let completed=false;const slow=context.request.get(base+'/plugins/demandas/front/time-entry-work-packages.php?query=98001').then(r=>{completed=true;return r;});
  for(let i=0;i<20;i++){if(docker(['cat','/tmp/time-performance-started']).trim())break;await new Promise(r=>setTimeout(r,50));}
  assert(docker(['cat','/tmp/time-performance-started']).trim());
  assert.equal((await context.request.get(url)).status(),200);assert.equal(completed,false,'Slow search blocked the session');await slow;mode('ok');
  console.log('PASS: slow API search does not lock another page in the same session');
  await page.goto(url);await page.locator('.te-edit').first().click();await page.locator('#teEdit').waitFor({state:'visible'});await page.locator('#teEdit .te-activity:not([disabled])').waitFor();
  await page.route('**/time-entry-activities.php?*',async route=>{
   const latest=new URL(route.request().url()).searchParams.get('wp')==='98999';
   if(!latest)await new Promise(r=>setTimeout(r,700));
   try{await route.fulfill({contentType:'application/json',body:JSON.stringify({ok:true,activities:[{href:'/api/v3/time_entries/activities/1',name:latest?'Atividade atual':'Resposta antiga'}]})});}catch{/* Previous request may have been aborted. */}
  });
  const selector=page.locator('#teEdit .te-wp');
  await selector.evaluate(el=>{el.dispatchEvent(new Event('change',{bubbles:true}));const option=new Option('WP sintética','0:98999');option.dataset.wp='98999';el.add(option);});
  await selector.selectOption('0:98999');await page.waitForTimeout(1000);
  assert((await page.locator('#teEdit .te-activity').innerText()).includes('Atividade atual'));
  assert(!(await page.locator('#teEdit .te-activity').innerText()).includes('Resposta antiga'));
  console.log('PASS: stale activity responses cannot replace the latest WP selection');
  await page.goto(base+'/plugins/demandas/front/timeclock.php');
  for(const theme of ['light','dark'])for(const width of [390,1366]){
   await page.setViewportSize({width,height:768});await page.reload();await page.locator('html').evaluate((el,t)=>{el.setAttribute('data-glpi-theme',t==='dark'?'auror_dark':'auror');el.setAttribute('data-glpi-theme-dark',t==='dark'?'1':'0');},theme);
   for(const key of ['consolidated','general','month','days']){const button=page.locator('[data-bs-target="#tm-help-'+key+'"]');await button.focus();await page.keyboard.press('Enter');const modal=page.locator('#tm-help-'+key);await modal.waitFor({state:'visible'});const bounds=await modal.locator('.modal-content').boundingBox();assert(bounds.x>=0&&bounds.x+bounds.width<=width+1);await modal.getByRole('button',{name:'Entendi',exact:true}).click();await modal.waitFor({state:'hidden'});await page.waitForFunction(selector=>document.activeElement===document.querySelector(selector),'[data-bs-target="#tm-help-'+key+'"]',{timeout:5000});}
  }
  console.log('PASS: four keyboard-accessible help modals, focus return, mobile/desktop and both themes');
  assert.deepEqual(errors,[],'JavaScript errors');
 }finally{await browser.close();docker(['kill',pid]);}
})().catch(e=>{console.error(e);process.exitCode=1;});
