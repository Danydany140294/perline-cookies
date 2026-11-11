import { defineConfig } from '@playwright/test';

// Lancé par run.mjs (qui prépare la base jetable et l'application) : voir README.md
export default defineConfig({
  testDir: './tests',
  globalSetup: './lib/global-setup.js',
  timeout: 60_000,
  expect: { timeout: 10_000 },
  workers: 1,            // les scénarios partagent une base : exécution séquentielle
  fullyParallel: false,
  retries: 0,
  reporter: [['list'], ['html', { open: 'never' }]],
  use: {
    baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8001',
    channel: 'chrome',   // Chrome installé sur la machine : aucun téléchargement de navigateur
    headless: true,
    locale: 'fr-FR',
    storageState: '.auth/utilisateur.json',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
  },
});
