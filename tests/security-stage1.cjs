// Synthetic users/data, guarded disposable Docker database only.
const {chromium}=require(process.env.PLAYWRIGHT_MODULE||'playwright');
const {spawnSync}=require('node:child_process');
const {randomBytes}=require('node:crypto');
const assert=require('node:assert/strict');
const base='http://127.0.0.1:8386';
const baseline=process.argv.includes('--baseline');
function fixture(input){const r=spawnSync('docker',['exec','-i','demandas-test-021-glpi','php','/tmp/security-stage1-fixture.php'],{input:JSON.stringify(input),encoding:'utf8'});assert.equal(r.status,0,r.stdout+r.stderr);return JSON.parse(r.stdout);}
(async()=>{
 const password=randomBytes(24).toString('hex');
 const seed=fixture({mode:'seed',password});
 const browser=await chromium.launch({channel:'msedge',headless:true});
 async function login(role){const context=await browser.newContext();const page=await context.newPage();await page.goto(base);await page.locator('[name=login_name]').fill('security-'+role);await page.locator('[name=login_password]').fill(password);await Promise.all([page.waitForNavigation(),page.locator('[name=submit]').click()]);return {context,page};}
 async function post(context,page,path,form,csrf=true){await page.reload();const token=await page.locator('input[name=_glpi_csrf_token]').first().inputValue();return context.request.post(base+'/plugins/demandas/front/'+path,{form:{...form,...(csrf?{_glpi_csrf_token:token}:{})},maxRedirects:0});}
 try{
  if(process.argv.includes('--ui')) {
   const {context,page}=await login('admin');
   assert.equal((await page.goto(base+'/front/user.form.php?id='+seed.users.internal+'&forcetab=GlpiPlugin%5CDemandas%5CUserTimeProfile%241')).status(),200);
   const form=page.locator('form').filter({has:page.locator('input[name=save_rights]')});
   await form.waitFor();
   assert.equal(await form.locator('select[name^="rights["]').count(),8);
   const before=fixture({mode:'snapshot'}).user_rights;
   await Promise.all([page.waitForNavigation(),form.getByRole('button',{name:'Salvar permissões individuais'}).click()]);
   assert.deepEqual(fixture({mode:'snapshot'}).user_rights,before,'Unchanged form changed permissions');
   await page.goto(base+'/plugins/demandas/front/timeclock-admin.php');
   assert.equal(await page.locator('[name=right_name] option').count(),8);
   await context.close();console.log('PASS: both permission forms render the same eight rights and unchanged save preserves values');return;
  }
  const {context,page}=await login('internal');
  assert.equal((await page.goto(base+'/plugins/demandas/front/operational-health.php')).status(),200);
  const before=fixture({mode:'snapshot'});
  assert.equal((await post(context,page,'operational-health.form.php',{action:'run'})).status(),302);
  const after=fixture({mode:'snapshot'});
  const hidden=after.findings.find(f=>Number(f.tickets_id)===seed.tickets.hidden);
  assert.equal(hidden.state,baseline?'resolved':'acknowledged');
  if(!baseline) for(const kind of ['hidden','foreign','deleted']) assert.deepEqual(after.findings.find(f=>Number(f.tickets_id)===seed.tickets[kind]),before.findings.find(f=>Number(f.tickets_id)===seed.tickets[kind]),kind+' untouched');
  assert.equal(after.findings.find(f=>Number(f.tickets_id)===seed.tickets.visible).state,'resolved');
  await context.close();
  const client=await login('client');
  const response=await post(client.context,client.page,'user-time-profile.form.php',{users_id:seed.users.client,save_settings:1,work_start:'08:00',work_end:'17:00'});
  assert.equal(response.status(),baseline?302:403);
  const settings=fixture({mode:'snapshot'}).user_time_settings.filter(r=>Number(r.users_id)===seed.users.client);
  if(baseline) assert(settings.length>0);
  await client.context.close();
  console.log(baseline?'REPRODUCED: inaccessible finding resolved and client settings POST accepted':'PASS: scope and direct POST authorization');
  if(baseline) return;
  // Direct endpoints must apply feature permissions, target scope and native CSRF.
  for(const role of ['admin','internal','viewer','client','noportal']) {
   const {context,page}=await login(role);
   const self=seed.users[role];
   const before=fixture({mode:'snapshot'});
   const deniedTargets=role==='admin'?[seed.users.foreign,99999999]:[seed.users.foreign,seed.users.admin];
   for(const target of deniedTargets) for(const [endpoint,fields] of [
    ['time-settings.form.php',{work_start:'06:00',work_end:'18:00'}],
    ['punch.form.php',{punch_date:'2026-10-20','records[0][time]':'08:00',absence_action:'keep'}],
    ['user-time-profile.form.php',{save_settings:1,work_start:'06:00',work_end:'18:00'}],
    ['user-time-profile.form.php',{save_rights:1,'rights[demandas_view_time_portal]':1}],
   ]) assert.equal((await post(context,page,endpoint,{users_id:target,...fields})).status(),403,role+' '+endpoint+' target '+target);
   for(const endpoint of ['timeclock.php','timeclock-audit.php','absence-list.php','absence-file.php']) {
    const response=await context.request.get(base+'/plugins/demandas/front/'+endpoint+'?users_id='+seed.users.foreign+'&id=1');
    assert.equal(response.status(),403,role+' foreign '+endpoint);
   }
   if(['client','noportal'].includes(role)) {
    for(const endpoint of ['time-settings.form.php','punch.form.php','user-time-profile.form.php']) assert.equal((await post(context,page,endpoint,{users_id:self,save_settings:1,punch_date:'2026-10-20'})).status(),403);
    assert.equal((await post(context,page,'time-entry.form.php',{action:'sync',entry_id:1})).status(),403);
   }
   if(role!=='admin') assert.equal((await post(context,page,'timeclock-admin.php',{save_access:1,users_id:self,right_name:'demandas_view_time_portal',decision:1})).status(),403);
   if(['viewer','client','noportal'].includes(role)) assert.equal((await post(context,page,'operational-health.form.php',{action:'run'})).status(),403);
   assert.deepEqual(fixture({mode:'snapshot'}),before,'Rejected operations changed data: '+role);
   if(['admin','internal','viewer'].includes(role)) {
    assert.equal((await page.goto(base+'/plugins/demandas/front/timeclock.php')).status(),200);
    assert.equal(await page.locator('option[value="'+seed.users.foreign+'"]').count(),0,'Foreign user leaked in picker');
    const data={users_id:self,work_start:'08:00',work_end:'17:00',lunch_minutes:60,tolerance_minutes:5};
    assert.equal((await post(context,page,'time-settings.form.php',data,false)).status(),403);
    assert.equal((await post(context,page,'time-settings.form.php',data)).status(),302);
    await page.reload();
    assert.equal((await post(context,page,'punch.form.php',{users_id:self,punch_date:'2026-10-20','records[0][time]':'08:00','records[1][time]':'17:00',absence_action:'keep'})).status(),302);
    assert.equal(fixture({mode:'snapshot'}).punches.filter(p=>Number(p.users_id)===self&&p.punch_at.startsWith('2026-10-20')).length,2);
   }
   await context.close();console.log('PASS: direct endpoints '+role);
  }
  const admin=await login('admin');
  await admin.page.goto(base+'/plugins/demandas/front/timeclock-admin.php');
  fixture({mode:'legacy-exception'});
  const rightsBefore=fixture({mode:'snapshot'});
  // Unknown rights and malformed decisions must reject the whole batch, even after a valid item.
  for(const fields of [
   {'rights[demandas_log_own_time]':0,'rights[demandas_manage_config]':1},
   {'rights[demandas_view_time_portal]':'2'},
   {'rights[demandas_view_time_portal]':'1x'},
   {'rights[demandas_view_time_portal][]':'1'},
  ]) {
   await post(admin.context,admin.page,'user-time-profile.form.php',{save_rights:1,users_id:seed.users.internal,...fields});
   assert.deepEqual(fixture({mode:'snapshot'}),rightsBefore,'Invalid batch changed data');
   await admin.page.reload();
  }
  for(const decision of ['0','1','-1']) {
   assert.equal((await post(admin.context,admin.page,'timeclock-admin.php',{save_access:1,users_id:seed.users.internal,right_name:'demandas_log_own_time',decision})).status(),302);
   const s=fixture({mode:'snapshot'});
   const row=s.user_rights.find(r=>Number(r.users_id)===seed.users.internal&&r.right_name==='demandas_log_own_time');
   assert.equal(row?String(row.decision):'-1',decision);
   assert(s.time_audit.some(a=>a.action==='access.update'&&Number(a.actor_users_id)===seed.users.admin&&JSON.parse(a.details_json).after===Number(decision)));
   assert.deepEqual(s.user_rights.filter(r=>r.right_name==='demandas_manage_config'),rightsBefore.user_rights.filter(r=>r.right_name==='demandas_manage_config'),'Legacy rights preserved');
   await admin.page.reload();
  }
  for(const decision of ['0','1']) {
   fixture({mode:'right',role:'internal',right:'demandas_view_own_attendance',enabled:decision==='0'});
   await post(admin.context,admin.page,'timeclock-admin.php',{save_access:1,users_id:seed.users.internal,right_name:'demandas_view_own_attendance',decision});
   const affected=await login('internal');
   const result=await post(affected.context,affected.page,'time-settings.form.php',{users_id:seed.users.internal,work_start:'08:00',work_end:'17:00'});
   assert.equal(result.status(),decision==='0'?403:302,'Individual decision overrides profile');
   await affected.context.close();
  }
  assert.equal((await post(admin.context,admin.page,'user-time-profile.form.php',{users_id:seed.users.internal,save_settings:1,work_start:'09:00',work_end:'18:00'})).status(),302);
  await admin.context.close();console.log('PASS: finite exceptions, atomic rejection, audit, legacy preservation and delegated settings');
  const operator=await login('internal');await operator.page.goto(base+'/plugins/demandas/front/operational-health.php');
  const untouched=fixture({mode:'snapshot'});
  const hiddenFinding=untouched.findings.find(f=>Number(f.tickets_id)===seed.tickets.hidden);
  await post(operator.context,operator.page,'operational-health.form.php',{action:'acknowledge',finding_id:hiddenFinding.id});
  assert.deepEqual(fixture({mode:'snapshot'}),untouched);
  await operator.page.reload();
  fixture({mode:'classification-failure'});
  const failedBefore=fixture({mode:'snapshot'}).findings;
  await post(operator.context,operator.page,'operational-health.form.php',{action:'run'});
  assert.deepEqual(fixture({mode:'snapshot'}).findings,failedBefore,'Failed evaluation resolved findings');
  fixture({mode:'classification-eligible'});
  await post(operator.context,operator.page,'operational-health.form.php',{action:'run'});
  let occurrence=fixture({mode:'snapshot'}).findings.find(f=>Number(f.tickets_id)===seed.tickets.visible);
  assert.equal(occurrence.state,'open');
  await post(operator.context,operator.page,'operational-health.form.php',{action:'ignore',finding_id:occurrence.id});
  await post(operator.context,operator.page,'operational-health.form.php',{action:'run'});
  const repeated=fixture({mode:'snapshot'}).findings;
  assert.equal(repeated.find(f=>Number(f.tickets_id)===seed.tickets.visible).state,'ignored','Repeat preserves treatment');
  assert.equal(repeated.filter(f=>Number(f.tickets_id)===seed.tickets.visible).length,1,'Repeat duplicates finding');
  await operator.context.close();console.log('PASS: hidden action and incomplete evaluation preserve findings');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
