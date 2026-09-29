import fs from 'node:fs';
import path from 'node:path';

const file = path.resolve(process.env.E2E_ENV_FILE || 'tests/e2e/.env');
if (fs.existsSync(file)) {
  for (const line of fs.readFileSync(file, 'utf8').split(/\r?\n/)) {
    const match = line.match(/^\s*([A-Z0-9_]+)\s*=\s*(.*?)\s*$/);
    if (match && !process.env[match[1]]) {
      process.env[match[1]] = match[2].replace(/^(['"])(.*)\1$/, '$2');
    }
  }
}

export const env = {
  baseURL: process.env.E2E_GLPI_BASE_URL || 'http://localhost:8180',
  admin: { user: process.env.E2E_GLPI_ADMIN_USER || '', password: process.env.E2E_GLPI_ADMIN_PASSWORD || '' },
  operator: { user: process.env.E2E_GLPI_OPERATOR_USER || '', password: process.env.E2E_GLPI_OPERATOR_PASSWORD || '' },
  viewer: { user: process.env.E2E_GLPI_VIEWER_USER || '', password: process.env.E2E_GLPI_VIEWER_PASSWORD || '' },
  restricted: { user: process.env.E2E_GLPI_RESTRICTED_USER || '', password: process.env.E2E_GLPI_RESTRICTED_PASSWORD || '' },
  isolated: process.env.E2E_ISOLATED_FIXTURE === '1',
  allowMutations: process.env.E2E_ALLOW_MUTATIONS === '1',
  allowArtifacts: process.env.E2E_ALLOW_ARTIFACTS === '1',
  updateManualScreenshots: process.env.E2E_UPDATE_MANUAL_SCREENSHOTS === '1'
};

export function assertConfigured(...accounts) {
  const missing = accounts.filter((account) => !account.user || !account.password);
  if (missing.length) {
    throw new Error('Configure as credenciais fictícias em tests/e2e/.env antes de executar a suíte.');
  }
}

export function assertDistinctAuthorizationAccounts() {
  const normalize = (user) => user.trim().toLocaleLowerCase('pt-BR');
  const privileged = [env.operator.user, env.admin.user].filter(Boolean).map(normalize);
  if (env.viewer.user && privileged.includes(normalize(env.viewer.user))) {
    throw new Error('E2E_GLPI_VIEWER_USER deve ser diferente de administrador e operador para validar a permissão somente leitura.');
  }
  if (env.restricted.user && [...privileged, env.viewer.user].filter(Boolean).map(normalize).includes(normalize(env.restricted.user))) {
    throw new Error('E2E_GLPI_RESTRICTED_USER deve ser uma conta distinta, sem direitos técnicos nem operacionais.');
  }
}
