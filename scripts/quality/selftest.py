"""Negative controls for release gates; all fixtures live in a temporary directory."""
import importlib.util
from pathlib import Path
import tempfile
import unittest
import subprocess
import sys
import json
from unittest.mock import patch

container = None
if '--php-container' in sys.argv:
    index = sys.argv.index('--php-container')
    container = sys.argv[index + 1]
    del sys.argv[index:index + 2]
    if container != 'demandas-test-021-glpi':
        raise RuntimeError('Somente o container descartável.')

spec = importlib.util.spec_from_file_location('quality', Path(__file__).with_name('check.py'))
quality = importlib.util.module_from_spec(spec)
spec.loader.exec_module(quality)
runner_spec = importlib.util.spec_from_file_location('runner', Path(__file__).with_name('run.py'))
runner = importlib.util.module_from_spec(runner_spec)
runner_spec.loader.exec_module(runner)
temporary_root = quality.ROOT / 'dist/quality'
temporary_root.mkdir(parents=True, exist_ok=True)

class Gates(unittest.TestCase):
    def test_mutation_target_guard(self):
        for scenario in ['wrong-host', 'wrong-port', 'shared-volume']:
            app = {'Config': {'Env': ['GLPI_DB_HOST=' + ('production' if scenario == 'wrong-host' else runner.DB)]},
                   'State': {'Running': True}, 'Mounts': [{'Destination': '/var/glpi', 'Source': 'fixture-data'}],
                   'NetworkSettings': {'Ports': {'80/tcp': [{'HostPort': '8180' if scenario == 'wrong-port' else '8386'}]}}}
            database = {'Config': {'Image': 'mariadb:11.4'}, 'State': {'Running': True}, 'Mounts': []}
            shared = {'Mounts': [{'Destination': '/var/glpi', 'Source': 'fixture-data'}]}
            def fake(*args, **kwargs):
                if args[:2] == ('docker', 'inspect'):
                    return json.dumps([app if args[2] == runner.C else database if args[2] == runner.DB else shared])
                return 'demandas-hml1109-glpi\n'
            with patch.object(runner, 'run', fake), self.assertRaises(RuntimeError, msg=scenario):
                runner.guard()

    @unittest.skipUnless(container, 'PHP token controls require --php-container')
    def test_php_rules(self):
        with tempfile.TemporaryDirectory(dir=temporary_root) as folder:
            root = Path(folder)
            # Linux mkdtemp uses 0700; copied fixtures must be readable by www-data.
            root.chmod(0o755)
            (root / 'plugins/demandas').mkdir(parents=True)
            source = root / 'plugins/demandas/check.php'
            remote = '/tmp/quality-negative-' + root.name
            for content, accepted in [
                ('<?php /* eval($x); */ $x = "system($x)";', True),
                ('<?php eval($x);', False),
                ('<?php system($x);', False),
                ('<?php $x = `id`;', False),
                ('<?php $DB->query($sql);', False),
                ('<?php if (', False),
            ]:
                source.write_text(content)
                subprocess.run(['docker', 'exec', container, 'mkdir', '-p', remote], check=True)
                subprocess.run(['docker', 'cp', str(root) + '/.', container + ':' + remote], check=True, capture_output=True)
                result = subprocess.run(['docker', 'exec', '-e', 'DEMANDAS_QUALITY_ROOT=' + remote,
                                         container, 'php', '/tmp/quality-static.php'], capture_output=True)
                self.assertEqual(result.returncode == 0, accepted)

    def test_secret_redaction(self):
        value = b'gh' + b'p_' + b'A' * 36
        with self.assertRaises(ValueError) as failure:
            quality.scan('fixture.txt', value)
        self.assertNotIn(value.decode(), str(failure.exception))

    def test_private_file_rejected(self):
        old = quality.PLUGIN
        with tempfile.TemporaryDirectory(dir=temporary_root) as folder:
            quality.PLUGIN = Path(folder)
            try:
                for name in ['glpicrypt.key', '.env', 'dump.sql', 'backup.zip', 'config_db.php']:
                    path = quality.PLUGIN / name
                    path.write_text('synthetic')
                    with self.assertRaises(ValueError):
                        quality.package_files()
                    path.unlink()
            finally:
                quality.PLUGIN = old

    def test_test_folder_rejected(self):
        old = quality.PLUGIN
        with tempfile.TemporaryDirectory(dir=temporary_root) as folder:
            quality.PLUGIN = Path(folder)
            try:
                (quality.PLUGIN / 'tests').mkdir()
                (quality.PLUGIN / 'tests/fixture.php').write_text('<?php')
                with self.assertRaises(ValueError):
                    quality.package_files()
            finally:
                quality.PLUGIN = old

if __name__ == '__main__':
    unittest.main()
