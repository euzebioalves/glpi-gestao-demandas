import { defineConfig } from '@playwright/test';
import './tests/e2e/env.mjs';

const isIsolatedFixture = process.env.E2E_ISOLATED_FIXTURE === '1';
const allowArtifacts = process.env.E2E_ALLOW_ARTIFACTS === '1';

export default defineConfig({
  testDir: './tests/e2e',
  fullyParallel: false,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 1 : 0,
  workers: 1,
  reporter: [['list'], ['html', { open: 'never' }]],
  timeout: 60_000,
  use: {
    baseURL: process.env.E2E_GLPI_BASE_URL || 'http://localhost:8180',
    browserName: 'chromium',
    headless: true,
    ignoreHTTPSErrors: true,
    // Artefatos podem conter dados exibidos pelo GLPI. Só são produzidos em
    // bases descartáveis explicitamente marcadas como fictícias.
    screenshot: isIsolatedFixture && allowArtifacts ? 'only-on-failure' : 'off',
    trace: isIsolatedFixture && allowArtifacts ? 'retain-on-failure' : 'off',
    video: isIsolatedFixture && allowArtifacts ? 'retain-on-failure' : 'off',
    viewport: { width: 1440, height: 1000 }
  }
});
