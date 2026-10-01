"""Synthetic regression: isolated GLPI 11.0.9 at localhost:8386. See RELEASE_0.23.0.md."""
import http.cookiejar, io, json, os, re, secrets, subprocess, urllib.error, urllib.parse, urllib.request, zipfile
import xml.etree.ElementTree as ET

BASE = os.environ.get('RECONCILIATION_BASE', 'http://127.0.0.1:8386')
CONTAINER = os.environ.get('RECONCILIATION_CONTAINER', 'demandas-test-021-glpi')
PAGE = '/plugins/demandas/front/legacy-reconciliation.php'
FORM = '/plugins/demandas/front/legacy-reconciliation.form.php'
def fixture(mode, **args):
    p = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'php', '/tmp/legacy-reconciliation-fixture.php'], input=json.dumps({'mode': mode, **args}), text=True, capture_output=True, check=True)
    return json.loads(p.stdout)
def check(condition, label):
    if not condition: raise AssertionError(label)
    print('PASS:', label)
def request(opener, path, data=None):
    req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data, doseq=True).encode() if data is not None else None)
    try: response = opener.open(req, timeout=90)
    except urllib.error.HTTPError as error: response = error
    return response.status, response.read()
def csrf(html):
    return re.findall(rb'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)', html)[-1].decode()
def login(role):
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    _, html = request(opener, '/')
    status, html = request(opener, '/front/login.php', {'login_name': 'reconciliation-' + role, 'login_password': password, '_glpi_csrf_token': csrf(html), 'noAUTO': '1'})
    check(status == 200 and b'name="login_password"' not in html, 'login ' + role)
    return opener
def reconcile(opener, kind, token=True):
    _, html = request(opener, PAGE)
    data = {'pairs[]': [f"{seed['tickets'][kind]}:{seed['wps'][kind]}"], 'confirm': '1'}
    if token: data['_glpi_csrf_token'] = csrf(html)
    return request(opener, FORM, data)
def transfer(opener, kind, token=True):
    _, html = request(opener, PAGE)
    data = {'action': 'transfer', 'pair': f"{seed['tickets'][kind]}:{seed['wps'][kind]}", 'confirm_transfer': '1'}
    if token: data['_glpi_csrf_token'] = csrf(html)
    return request(opener, FORM, data)
def export_ids(opener, scope='open'):
    status, content = request(opener, '/plugins/demandas/front/dashboard-export.php?format=xlsx&ticket_scope=' + scope)
    check(status == 200 and content.startswith(b'PK'), 'exportação Excel ' + scope)
    ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    with zipfile.ZipFile(io.BytesIO(content)) as archive:
        root = ET.fromstring(archive.read('xl/worksheets/sheet2.xml'))
        return {int(row.find('s:c/s:v', ns).text) for row in root.findall('.//s:sheetData/s:row', ns)[1:]}

password = secrets.token_urlsafe(24)
seed = fixture('seed', password=password)
fixture('reset-imports')
check(fixture('parse')['cases'] == 11, 'URLs completas, origem, porta, subpasta e formatos inválidos')
admin = login('admin')
before = fixture('snapshot')
status, html = request(admin, PAGE)
check(status == 200, 'prévia administrativa')
check(b'&lt;b&gt;&amp; teste&lt;/b&gt;' in html and b'<b>& teste</b>' not in html, 'saída HTML escapada')
check(b'conflict1' in html and 'Conflito de vínculo'.encode() in html, 'mesma WP em chamados diferentes bloqueada')
check(b'other_entity' not in html and b'solved' not in html and b'closed' not in html, 'prévia respeita entidade e chamados abertos')
_, all_html = request(admin, PAGE + '?ticket_scope=all')
check(b'solved' in all_html and b'closed' in all_html, 'opção Todos preservada')
check(before == fixture('snapshot'), 'prévia não grava nem publica acompanhamentos')
open_ids = export_ids(admin)
all_ids = export_ids(admin, 'all')
check(seed['tickets']['open'] in open_ids and seed['tickets']['solved'] not in open_ids and seed['tickets']['closed'] not in open_ids, 'painel/exportação padrão excluem solucionados e fechados')
check(all_ids - open_ids == {seed['tickets']['solved'], seed['tickets']['closed']}, 'Todos inclui solucionados e fechados')
check(seed['tickets']['other_entity'] not in all_ids, 'exportação respeita entidades')
status, pdf = request(admin, '/plugins/demandas/front/dashboard-export.php?format=pdf')
check(status == 200 and pdf.startswith(b'%PDF-'), 'exportação PDF no novo escopo')
status, _ = reconcile(admin, 'open', token=False)
check(status == 403 and before == fixture('snapshot'), 'CSRF ausente recusado sem gravação')
for kind in ['conflict1', 'invalid', 'foreign', 'unauthorized', 'forbidden', 'missing', 'invalid_api', 'timeout']:
    status, _ = reconcile(admin, kind)
    check(status == 200 and before == fixture('snapshot'), 'falha segura sem vínculo: ' + kind)
status, _ = reconcile(admin, 'other_entity')
check(status == 403 and before == fixture('snapshot'), 'POST forjado de outra entidade recusado')
status, _ = reconcile(admin, 'open')
after = fixture('snapshot')
links = [row for row in after['glpi_plugin_demandas_links'] if int(row['openproject_work_package_id']) == seed['wps']['open']]
check(status == 200 and len(links) == 1 and int(links[0]['tickets_id']) == seed['tickets']['open'], 'vínculo existente importado com GET da WP')
check(links[0]['public_phase'] == '' and before['glpi_itilfollowups'] == after['glpi_itilfollowups'], 'importação não publica fase nem acompanhamento')
check(any(row['event_type'] == 'legacy_link_reconciled' for row in after['glpi_plugin_demandas_events']), 'auditoria da conciliação')
reconcile(admin, 'open')
check(after == fixture('snapshot'), 'repetição idempotente preserva vínculo e histórico')
status, html = request(admin, '/plugins/demandas/front/dashboard.php?has_wp=yes&op_status=Em%20execu%C3%A7%C3%A3o')
check(status == 200 and b'QA concilia' in html, 'cobertura e filtro de status reconhecem WP conciliada')
internal = login('internal')
status, _ = reconcile(internal, 'internal')
check(status == 200 and any(int(row['openproject_work_package_id']) == 91004 for row in fixture('snapshot')['glpi_plugin_demandas_links']), 'perfil interno e coluna Fields legada')
readonly = login('readonly')
status, _ = reconcile(readonly, 'open')
check(status == 403, 'sem UPDATE nativo não concilia, inclusive vínculo já existente')
client = login('client')
status, _ = request(client, PAGE)
check(status == 403, 'cliente sem direito técnico bloqueado')
status, _ = request(client, FORM, {'_glpi_csrf_token': csrf(request(client, '/front/helpdesk.public.php')[1]), 'pairs[]': ['1:91001'], 'confirm': '1'})
check(status == 403, 'backend bloqueia cliente')
status, html = request(admin, PAGE)
check(status == 200 and b'Detalhes' in html and b'/work_packages/91005' in html, 'conflito detalhado e link da WP no OpenProject')
status, _ = transfer(admin, 'transfer_target', token=False)
check(status == 403, 'transferência sem CSRF recusada')
for protection in ['public', 'time']:
    fixture('protect-transfer', kind=protection)
    protected = fixture('snapshot')
    status, _ = transfer(admin, 'transfer_target')
    check(status == 200 and fixture('snapshot') == protected, 'transferência com histórico bloqueada: ' + protection)
fixture('protect-transfer', kind='clear')
status, _ = transfer(admin, 'transfer_target')
transferred = fixture('snapshot')
link = next(row for row in transferred['glpi_plugin_demandas_links'] if int(row['openproject_work_package_id']) == 91008)
events = [row for row in transferred['glpi_plugin_demandas_events'] if int(row['openproject_work_package_id']) == 91008 and row['event_type'] == 'legacy_link_transferred']
check(status == 200 and int(link['tickets_id']) == seed['tickets']['transfer_target'] and len(events) == 2, 'transferência local segura e auditada')
internal = login('internal')
status, _ = transfer(internal, 'transfer_target')
check(status == 403, 'transferência exige direito específico')
status, data = request(urllib.request.build_opener(), PAGE)
check(b'QA concilia' not in data, 'anônimo sem acesso aos chamados')
log = subprocess.run(['docker', 'exec', CONTAINER, 'cat', '/tmp/reconciliation-api-requests.log'], text=True, capture_output=True, check=True).stdout.splitlines()
check(log and all(line.startswith('GET /api/v3/work_packages/') for line in log), 'nenhuma criação ou alteração externa: somente GET de WPs')
print('Conciliação e painel: regressões aprovadas.')
