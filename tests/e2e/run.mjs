import { spawnSync } from 'node:child_process';
import './env.mjs';

const mode = process.argv[2] || '';
const env = { ...process.env };
if (mode === '--mutating' || mode === '--manual') {
  if (env.E2E_ISOLATED_FIXTURE !== '1') {
    throw new Error('Testes mutáveis exigem E2E_ISOLATED_FIXTURE=1 no arquivo de ambiente E2E selecionado.');
  }
  env.E2E_ALLOW_MUTATIONS = '1';
}
if (mode === '--manual') {
  env.E2E_UPDATE_MANUAL_SCREENSHOTS = '1';
}
const result = spawnSync(
  process.platform === 'win32' ? 'npx.cmd' : 'npx',
  ['playwright', 'test'],
  { stdio: 'inherit', env, shell: process.platform === 'win32' }
);
if (result.error) {
  throw result.error;
}
process.exit(result.status ?? 1);
