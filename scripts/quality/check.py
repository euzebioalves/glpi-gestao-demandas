"""Offline release gates using standard libraries only; never prints secret matches."""
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys
import zipfile

ROOT = Path(__file__).resolve().parents[2]
PLUGIN = ROOT / 'plugins/demandas'
SECRET_RULES = [
    re.compile(rb'-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----'),
    re.compile(rb'\bgh[pousr]_[A-Za-z0-9]{30,}\b'),
    re.compile(rb'\bgithub_pat_[A-Za-z0-9_]{40,}\b'),
    re.compile(rb'\bAKIA[0-9A-Z]{16}\b'),
]
FORBIDDEN = {'.env', 'integration.env', 'glpicrypt.key', 'config_db.php'}
ALLOWED = {'.php', '.js', '.css', '.md', '.json', '.svg', '.png', '.jpg', '.gif', '.txt'}

def run(*args):
    return subprocess.check_output(args, cwd=ROOT)

def scan(name, content):
    if any(pattern.search(content) for pattern in SECRET_RULES):
        raise ValueError(f'{name}: possível segredo; conteúdo omitido')

def package_files():
    result = {}
    for path in sorted(PLUGIN.rglob('*')):
        if path.is_symlink():
            raise ValueError(f'{path.name}: link simbólico não permitido no pacote')
        if path.is_dir():
            continue
        name = path.relative_to(PLUGIN).as_posix()
        if (path.name in FORBIDDEN or path.name.startswith('.') or path.suffix.lower() not in ALLOWED
                or any(p in {'tests', 'dist', 'vendor', 'node_modules'} for p in path.relative_to(PLUGIN).parts)):
            raise ValueError(f'{name}: arquivo inesperado no plugin')
        content = path.read_bytes()
        scan(name, content)
        result['demandas/' + name] = content
    return result

def check():
    # Tracked files only: never inspect private backups, local credentials or user attachments.
    names = set(run('git', 'ls-files', '-z').decode().split('\0'))
    # Include new source/control files, without reading unrelated user attachments.
    names.update(run('git', 'ls-files', '--others', '--exclude-standard', '-z', '--',
                     'plugins/demandas', 'scripts/quality', '.github', 'tests').decode().split('\0'))
    for name in sorted(names):
        if not name:
            continue
        path = ROOT / name
        if path.name in FORBIDDEN or path.suffix.lower() in {'.sql', '.pem', '.key'}:
            raise ValueError(f'{name}: material privado versionado')
        if path.is_file():
            scan(name, path.read_bytes())
    files = package_files()
    manifest = json.loads((ROOT / 'package.json').read_text())
    lock = json.loads((ROOT / 'package-lock.json').read_text())
    assert manifest['devDependencies'] == lock['packages']['']['devDependencies'], 'Lock divergente'
    for name, package in lock['packages'].items():
        if not name:
            continue
        assert package.get('integrity', '').startswith('sha512-'), f'Integridade ausente: {name}'
        assert package.get('resolved', '').startswith('https://registry.npmjs.org/'), f'Origem inesperada: {name}'
    print(f'PASS: segredos conhecidos, lockfile e conteúdo do pacote ({len(files)} arquivos)')
    return files

def build():
    files = check()
    version = re.search(rb"PLUGIN_DEMANDAS_VERSION', '([0-9.]+)'", files['demandas/setup.php']).group(1).decode()
    target = ROOT / 'dist/quality'
    target.mkdir(parents=True, exist_ok=True)
    archive = target / f'demandas-{version}.zip'
    with zipfile.ZipFile(archive, 'w', zipfile.ZIP_DEFLATED) as output:
        for name, content in files.items():
            entry = zipfile.ZipInfo(name, (2020, 1, 1, 0, 0, 0))
            entry.compress_type = zipfile.ZIP_DEFLATED
            entry.external_attr = 0o100644 << 16
            output.writestr(entry, content)
    with zipfile.ZipFile(archive) as output:
        assert set(output.namelist()) == set(files)
        assert all(output.read(name) == content for name, content in files.items())
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix('.sha256').write_text(f'{digest}  {archive.name}\n', encoding='ascii')
    provenance = {'version': version, 'commit': run('git', 'rev-parse', 'HEAD').decode().strip(),
                  'dirty': bool(run('git', 'status', '--porcelain', '--untracked-files=no').strip()),
                  'sha256': digest, 'files': {n: hashlib.sha256(c).hexdigest() for n, c in files.items()}}
    archive.with_suffix('.manifest.json').write_text(json.dumps(provenance, indent=2) + '\n', encoding='utf-8')
    print(f'PASS: ZIP determinístico conferido, checksum e manifesto em dist/quality ({version})')

if __name__ == '__main__':
    try:
        build() if '--package' in sys.argv else check()
    except (ValueError, AssertionError, KeyError) as error:
        print('FAIL:', error, file=sys.stderr)
        sys.exit(1)
