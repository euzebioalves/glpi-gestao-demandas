"""Fail-fast security regression runner. Mutations are confined to the named disposable DB."""
import argparse
import json
import os
from pathlib import Path
import secrets
import re
import subprocess
import sys
import time

ROOT = Path(__file__).resolve().parents[2]
C = 'demandas-test-021-glpi'
DB = 'demandas-test-021-db'

def run(*args, data=None, capture=False):
    result = subprocess.run(args, cwd=ROOT, input=data, text=True, encoding='utf-8',
                            stdout=subprocess.PIPE if capture else None, check=True)
    return result.stdout if capture else ''

def php(file, *args, data=None, capture=False):
    return run('docker', 'exec', '-i', C, 'php', '/tmp/' + file, *args, data=data, capture=capture)

def guard():
    info = json.loads(run('docker', 'inspect', C, capture=True))[0]
    dbinfo = json.loads(run('docker', 'inspect', DB, capture=True))[0]
    env = dict(v.split('=', 1) for v in info['Config']['Env'] if '=' in v)
    if env.get('GLPI_DB_HOST') != DB or not info['State']['Running']:
        raise RuntimeError('A execução exige o container e banco descartáveis em funcionamento.')
    if not dbinfo['State']['Running'] or not dbinfo['Config']['Image'].startswith('mariadb:11.4'):
        raise RuntimeError('MariaDB descartável fora da matriz esperada.')
    data_mounts = [m for item in [info, dbinfo] for m in item['Mounts']
                   if m['Destination'] in ['/var/glpi', '/var/lib/mysql']]
    for mount in data_mounts:
        if 'hml' in (mount.get('Name', '') + mount.get('Source', '')):
            raise RuntimeError('Volume de homologação não permitido.')
    existing = run('docker', 'ps', '-a', '--format', '{{.Names}}', capture=True).splitlines()
    for shared in ['demandas-hml1109-glpi', 'demandas-hml1109-glpi-db']:
        if shared in existing:
            shared_info = json.loads(run('docker', 'inspect', shared, capture=True))[0]
            shared_sources = {m['Source'] for m in shared_info['Mounts'] if m['Destination'] in ['/var/glpi', '/var/lib/mysql']}
            if any(m['Source'] in shared_sources for m in data_mounts):
                raise RuntimeError('O fixture compartilha dados com a homologação; execução recusada.')
    ports = info['NetworkSettings']['Ports'].get('80/tcp') or []
    if not any(p['HostPort'] == '8386' for p in ports):
        raise RuntimeError('Porta do fixture precisa ser 8386.')

def provision():
    # CI only, on a fresh hosted runner. Never replace existing containers/volumes.
    if os.environ.get('GITHUB_ACTIONS') != 'true':
        raise RuntimeError('--provision-ci é exclusivo do runner efêmero do GitHub.')
    existing = run('docker', 'ps', '-a', '--format', '{{.Names}}', capture=True).splitlines()
    if C in existing or DB in existing:
        raise RuntimeError('Container já existe; nenhuma substituição será feita.')
    run('docker', 'build', '-t', 'demandas-quality:11.0.9', '-f', 'docker/glpi/Dockerfile', '.')
    network = 'demandas-quality'
    run('docker', 'network', 'create', network)
    password = secrets.token_hex(24)
    os.environ['MARIADB_ROOT_PASSWORD'] = password
    os.environ['GLPI_DB_PASSWORD'] = password
    run('docker', 'run', '-d', '--name', DB, '--network', network,
        '-e', 'MARIADB_ROOT_PASSWORD', '-e', 'MARIADB_DATABASE=glpi', 'mariadb:11.4')
    for _ in range(90):
        if subprocess.run(['docker', 'exec', DB, 'healthcheck.sh', '--connect', '--innodb_initialized'],
                          stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL).returncode == 0:
            break
        time.sleep(2)
    else:
        raise RuntimeError('MariaDB não ficou disponível.')
    run('docker', 'run', '-d', '--name', C, '--network', network, '-p', '127.0.0.1:8386:80',
        '-e', 'GLPI_DB_HOST=' + DB, '-e', 'GLPI_DB_NAME=glpi', '-e', 'GLPI_DB_USER=root',
        '-e', 'GLPI_DB_PASSWORD', '-e', 'GLPI_SKIP_AUTOINSTALL=true', '-e', 'GLPI_SKIP_AUTOUPDATE=true',
        '-e', 'GLPI_CRONTAB_ENABLED=0', '-v', str(ROOT / 'plugins') + ':/var/www/glpi/plugins:ro',
        'demandas-quality:11.0.9')
    # Entrypoint must finish creating configuration before invoking CLI.
    for _ in range(90):
        if subprocess.run(['docker', 'exec', C, 'test', '-d', '/var/glpi/config']).returncode == 0:
            break
        time.sleep(2)
    else:
        raise RuntimeError('Diretórios do GLPI não ficaram disponíveis.')
    run('docker', 'exec', C, 'sh', '-c',
        'php bin/console database:install --db-host="$GLPI_DB_HOST" --db-name="$GLPI_DB_NAME" '
        '--db-user="$GLPI_DB_USER" --db-password="$GLPI_DB_PASSWORD" --no-interaction --no-telemetry')
    os.environ.pop('MARIADB_ROOT_PASSWORD', None)
    os.environ.pop('GLPI_DB_PASSWORD', None)

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--integration', action='store_true')
    parser.add_argument('--provision-ci', action='store_true')
    args = parser.parse_args()
    if args.provision_ci:
        provision()
    guard()
    run(sys.executable, 'scripts/quality/check.py')
    for file in sorted((ROOT / 'tests').glob('*.php')):
        run('docker', 'cp', str(file), C + ':/tmp/' + file.name, capture=True)
    run('docker', 'cp', str(ROOT / 'scripts/quality/static.php'), C + ':/tmp/quality-static.php', capture=True)
    # The scanner supports its copied location without copying private workspace files.
    run('docker', 'exec', '-e', 'DEMANDAS_QUALITY_ROOT=/var/www/glpi', C, 'php', '/tmp/quality-static.php')
    run(sys.executable, 'scripts/quality/selftest.py', '--php-container', C)
    # TOKEN_PARSE above checks every PHP source and returns nonzero on syntax errors.
    for file in sorted((ROOT / 'plugins/demandas/public').rglob('*.js')):
        run('node', '--check', str(file))
    # Unit test resolves source relative to /tests.
    run('docker', 'exec', C, 'mkdir', '-p', '/var/www/glpi/tests')
    run('docker', 'cp', str(ROOT / 'tests/action-dashboard-unit.php'), C + ':/var/www/glpi/tests/action-dashboard-unit.php')
    run('docker', 'exec', C, 'php', '/var/www/glpi/tests/action-dashboard-unit.php')
    run('docker', 'cp', str(ROOT / 'scripts/quality/inventory.php'), C + ':/tmp/quality-inventory.php', capture=True)
    inventory = php('quality-inventory.php', capture=True)
    json.loads(inventory)  # Never write an error page as a successful inventory.
    (ROOT / 'dist/quality').mkdir(parents=True, exist_ok=True)
    (ROOT / 'dist/quality/runtime-inventory.json').write_text(inventory, encoding='utf-8')
    if args.integration:
        run('docker', 'exec', C, 'php', 'bin/console', 'plugin:install', '--force', '--no-interaction', '--username=glpi', 'demandas')
        run('docker', 'exec', C, 'php', 'bin/console', 'plugin:activate', '--no-interaction', 'demandas')
        php('security-stage1-fixture.php', data=json.dumps({'mode': 'seed', 'password': secrets.token_hex(24)}), capture=True)
        php('security-installation.php', 'upgrade')
        schema = 'glpi_security_clean_' + time.strftime('%Y%m%d_%H%M%S')
        dbuser = run('docker', 'exec', C, 'printenv', 'GLPI_DB_USER', capture=True).strip()
        if not re.fullmatch('[A-Za-z0-9_]+', dbuser):
            raise RuntimeError('Usuário de banco inesperado no fixture.')
        run('docker', 'exec', '-i', DB, 'sh', '-c',
            'mariadb -uroot -p"$MARIADB_ROOT_PASSWORD"',
            data=f"CREATE DATABASE `{schema}`; GRANT ALL ON `{schema}`.* TO '{dbuser}'@'%';")
        php('security-installation.php', 'clean', schema)
        php('indicators.php')
        php('security-content.php')
        # Mock servers bind loopback and are stopped even when tests fail.
        workers = []
        try:
            for port, file in [(8398, 'operations-api.php'), (8399, 'security-content-api.php')]:
                pid = run('docker', 'exec', C, 'sh', '-c',
                          f'php -S 127.0.0.1:{port} /tmp/{file} >/tmp/quality-{port}.log 2>&1 & echo $!', capture=True).strip()
                workers.append(pid)
            time.sleep(1)
            php('security-content-network.php')
            run('node', 'tests/security-stage1.cjs')
            run('node', 'tests/indicators-http.cjs')
            run(sys.executable, 'tests/operations.py')
            run('node', 'tests/operations-http.cjs')
        finally:
            for pid in workers:
                if pid.isdigit():
                    subprocess.run(['docker', 'exec', C, 'kill', pid], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    run(sys.executable, 'scripts/quality/check.py', '--package')
    print('PASS: quality gates concluídos' + (' com integração' if args.integration else ' (sem integração)'))

if __name__ == '__main__':
    try:
        main()
    except (RuntimeError, subprocess.CalledProcessError) as error:
        print('FAIL: etapa interrompida; nenhum resultado parcial representa aprovação.', file=sys.stderr)
        sys.exit(1)
