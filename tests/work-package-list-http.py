"""HTTP regression against isolated GLPI 11.0.9; see RELEASE_0.21.0.md."""
import http.cookiejar, io, json, pathlib, re, secrets, subprocess, tempfile
import urllib.error, urllib.parse, urllib.request, zipfile
import xml.etree.ElementTree as ET

BASE = 'http://127.0.0.1:8386'
CONTAINER = 'demandas-test-021-glpi'
OUT = pathlib.Path(tempfile.gettempdir()) / 'demandas-wp-021-tests'
OUT.mkdir(exist_ok=True)

def fixture(mode, **args):
    result = subprocess.run(['docker', 'exec', '-i', CONTAINER, 'php', '/tmp/work-package-list-fixture.php'], input=json.dumps({'mode': mode, **args}), capture_output=True, text=True, check=True)
    return json.loads(result.stdout)

def check(ok, label):
    if not ok:
        raise AssertionError(label)
    print('PASS:', label)

def request(opener, path, data=None):
    req = urllib.request.Request(BASE + path, data=urllib.parse.urlencode(data).encode() if data else None)
    try:
        response = opener.open(req, timeout=60)
    except urllib.error.HTTPError as error:
        response = error
    return response.status, response.headers, response.read()

def spreadsheet(data, expected, allowed_ids=None):
    with zipfile.ZipFile(io.BytesIO(data)) as archive:
        ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        root = ET.fromstring(archive.read('xl/worksheets/sheet1.xml'))
        check(len(root.findall('.//s:sheetData/s:row', ns)) == expected + 1, f'Excel: {expected} registros completos')
        check(not root.findall('.//s:f', ns), 'Excel sem fórmulas injetadas')
        if allowed_ids is not None:
            ids = [int(row.find('s:c/s:v', ns).text) for row in root.findall('.//s:sheetData/s:row', ns)[1:]]
            check(set(ids) == set(allowed_ids), 'Excel respeita usuário/filtro, sem trocar escopo por GET')

password = secrets.token_urlsafe(24)
users = fixture('seed', password=password)
check(fixture('unit')['ok'], 'limites de página, entradas inválidas e lista vazia')
before = fixture('snapshot')
page_path = '/plugins/demandas/front/work-package-monitor.php'
export = '/plugins/demandas/front/work-package-export.php'
for role in ['admin', 'internal', 'client']:
    opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
    _, _, login = request(opener, '/')
    token = re.search(rb'name=["\']_glpi_csrf_token["\']\s+value=["\']([^"\']+)', login)[1].decode()
    status, _, html = request(opener, '/front/login.php', {'login_name': 'wp-test-' + role, 'login_password': password, '_glpi_csrf_token': token, 'noAUTO': '1'})
    check(status == 200 and b'name="login_password"' not in html, 'login ' + role)
    expected = 0 if role == 'client' else 61
    status, _, html = request(opener, page_path)
    check(status == 200 and html.count(b'<tr><td>') == min(expected, 25), 'primeira página ' + role)
    if role != 'client':
        status, _, html = request(opener, page_path + '?page=3')
        check(status == 200 and html.count(b'<tr><td>') == 11, 'última página ' + role)
        status, _, html = request(opener, page_path + '?status=Novo&page=2')
        check(html.count(b'<tr><td>') == 6 and b'status=Novo' in html, 'filtro aplicado antes da paginação')
        status, _, html = request(opener, page_path + '?per_page=50')
        check(html.count(b'<tr><td>') == 50, 'tamanho configurável')
    status, headers, data = request(opener, export + '?format=xlsx&page=2&users_id=' + str(users['internal']))
    check(status == 200 and 'spreadsheetml' in headers.get('Content-Type', ''), 'download Excel pessoal ' + role)
    spreadsheet(data, expected, [] if role == 'client' else range(1001 if role == 'admin' else 2001, 1062 if role == 'admin' else 2062))
    (OUT / (role + '.xlsx')).write_bytes(data)
    status, headers, data = request(opener, export + '?format=pdf&status=Novo&page=2')
    check(status == 200 and data.startswith(b'%PDF-'), 'PDF pessoal ' + role)
    (OUT / (role + '.pdf')).write_bytes(data)
    if role != 'client':
        status, _, data = request(opener, export + '?format=xlsx&status=Novo&page=3')
        spreadsheet(data, 31)
    status, _, data = request(opener, export + '?format=xlsx&scope=consolidated')
    check(status == (200 if role == 'admin' else 403), 'permissão de exportação consolidada ' + role)
    if role == 'admin':
        spreadsheet(data, 122)
        (OUT / 'consolidated.xlsx').write_bytes(data)
        status, _, data = request(opener, export + '?format=pdf&scope=consolidated')
        check(status == 200 and data.startswith(b'%PDF-'), 'PDF consolidado')
        (OUT / 'consolidated.pdf').write_bytes(data)
    status, _, html = request(opener, '/plugins/demandas/front/work-package-consolidated.php')
    check(status == (403 if role == 'client' else 200), 'acesso consolidado ' + role)
    if role == 'internal':
        check(b'work-package-export.php' not in html, 'botões consolidados ocultos sem permissão de exportar')
    status, _, _ = request(opener, export + '?format=csv')
    check(status == 400, 'formato inválido recusado')
    status, _, _ = request(opener, export + '?format=pdf&scope=unknown')
    check(status == 400, 'escopo inválido recusado')
anonymous = urllib.request.build_opener()
status, _, data = request(anonymous, export + '?format=xlsx')
check(not data.startswith(b'PK'), 'sem exportação anônima')
check(before == fixture('snapshot'), 'listagem e exportações não modificam dados')
print('Artefatos fictícios:', OUT)
