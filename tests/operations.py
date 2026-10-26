"""Fault injection and independent PHP workers against disposable Docker only."""
import concurrent.futures, hashlib, hmac, json, subprocess, time, urllib.request, urllib.error

C = 'demandas-test-021-glpi'
def docker(*args, data=None):
    r = subprocess.run(['docker','exec','-i',C,*args], input=data, text=True, capture_output=True, check=True)
    return r.stdout
def fixture(mode, **args):
    return json.loads(docker('php','/tmp/operations-fixture.php',data=json.dumps({'mode':mode,**args})))
def mode(value):
    docker('sh','-c','cat > /tmp/operations-api-mode',data=value)
def state():
    return json.loads(docker('cat','/tmp/operations-api-state'))
def check(ok,label):
    if not ok: raise AssertionError(label)
    print('PASS:',label,flush=True)
def create():
    row=fixture('create');check('id' in row,'local entry created');return row['id']

fixture('configure');mode('ok')
check(fixture('local').get('ok'),'all local writes and audit roll back on invalid later record/absence/upload')
entry=create();mode('slow')
with concurrent.futures.ThreadPoolExecutor(2) as workers:
    results=list(workers.map(lambda _:fixture('sync',id=entry),range(2)))
check(sum(r.get('ok',False) for r in results)==1,'only one independent worker sends hours')
before=state()['posts'];mode('ok');fixture('sync',id=entry)
check(state()['posts']==before and fixture('row',id=entry)['sync_status']=='synced','repeat sync makes no POST')
for failure in ['lost','timeout']:
    entry=create();mode(failure);result=fixture('sync',id=entry);time.sleep(3 if failure=='timeout' else 0)
    before=state()['posts'];row=fixture('row',id=entry)
    check(not result.get('ok') and row['sync_status']=='uncertain','accepted POST with '+failure+' remains uncertain')
    for action in ['sync','edit','delete']:
        check(not fixture(action,id=entry).get('ok'),'uncertain record blocks '+action)
    check(state()['posts']==before,'uncertain retry never duplicates remote hours')
    mode('ok');check(not fixture('recover',id=entry,remote=999).get('ok'),'recovery rejects missing remote entry')
    check(fixture('recover',id=entry,remote=970000+before).get('ok'),'recovery verifies existing remote entry without POST')
    check(state()['posts']==before,'recovery creates no remote entry')
for failure in ['401','403','422']:
    entry=create();mode(failure);check(not fixture('sync',id=entry).get('ok') and fixture('row',id=entry)['sync_status']=='error','definite '+failure+' allows correction')
    mode('ok');check(fixture('sync',id=entry).get('ok'),'retry after definitive rejection succeeds')
entry=create();mode('lost');fixture('sync',id=entry);mode('ok')
check(not fixture('recover',id=entry).get('ok'),'absence requires explicit confirmation')
# Do not authorize resend here: mock actually accepted the entry.
remote=970000+state()['posts'];fixture('recover',id=entry,remote=remote)
mode('lost');check(not fixture('change',id=entry).get('ok'),'lost PATCH response preserved as pending operation')
check(fixture('row',id=entry)['sync_status']=='update_pending','local values not falsely confirmed')
check(json.loads(docker('php','/tmp/security-installation.php','upgrade'))['ok'],'repeated upgrade preserves pending operation and rights')
mode('ok');check(fixture('sync',id=entry).get('ok') and fixture('row',id=entry)['minutes']==90,'PATCH retried on same ID, local values committed')
mode('lost');check(not fixture('delete',id=entry).get('ok'),'lost DELETE response retains local recovery state')
mode('ok');check(fixture('sync',id=entry).get('ok') and fixture('row',id=entry) is None,'DELETE retry accepts remote absence and commits local delete')
link=fixture('link');check('link' in link,'synthetic ticket/link created')
fixture('followup-unfail');fixture('followup-fail')
try:
    check(not fixture('followup').get('ok'),'failure after followup creation reported')
    result=fixture('followup-state',**link)
    check(result['followups']==0 and result['jobs'][0]['state']=='pending','followup rolled back, persistent job survives')
finally: fixture('followup-unfail')
check(fixture('followup').get('ok'),'unchanged status retries pending followup')
check(fixture('followup').get('ok'),'repeated sync succeeds')
result=fixture('followup-state',**link)
check(result['followups']==1 and len(result['jobs'])==1 and result['jobs'][0]['state']=='sent','exactly one persisted followup')
mode('status2');fixture('followup');mode('ok');fixture('followup')
check(fixture('followup-state',**link)['followups']==3,'return to previous status is a new transition')
body=json.dumps({'action':'work_package:updated','work_package':{'id':98002}}).encode()
def webhook(signature):
    request=urllib.request.Request('http://127.0.0.1:8386/plugins/demandas/webhook.php',data=body,headers={'Content-Type':'application/json','X-Op-Signature':signature})
    try:
        with urllib.request.urlopen(request,timeout=30) as result:return result.status
    except urllib.error.HTTPError as result:return result.code
before=fixture('followup-state',**link)
check(webhook('invalid')==401,'invalid webhook signature rejected')
check(fixture('followup-state',**link)==before,'invalid signature leaves jobs and followups unchanged')
signature='sha256='+hmac.new(b'synthetic-stage2-webhook',body,hashlib.sha256).hexdigest()
check(webhook(signature)==200 and webhook(signature)==200,'signed stateless webhook and repeat succeed')
check(fixture('followup-state',**link)==before,'signed repeated webhook creates no duplicate followup')
print('Stage 2 fault injection passed.')
