import fs from 'node:fs';
import path from 'node:path';
import { env } from './env.mjs';

const outputDir = path.resolve('docs/assets/manual');

export async function captureForManual(page, filename) {
  if (!env.updateManualScreenshots) {
    return;
  }
  if (!env.isolated || !env.allowArtifacts) {
    throw new Error('Capturas do manual exigem E2E_ISOLATED_FIXTURE=1 e E2E_ALLOW_ARTIFACTS=1 para impedir o uso de dados reais.');
  }
  fs.mkdirSync(outputDir, { recursive: true });
  await page.screenshot({ path: path.join(outputDir, filename), fullPage: true });
}
