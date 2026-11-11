// Connexion une seule fois (limiteur de débit de la connexion : 5 tentatives / 15 min) : la session
// est sauvegardée dans .auth/ et réutilisée par tous les scénarios.
import { chromium } from '@playwright/test';
import { mkdirSync } from 'node:fs';

export default async function globalSetup() {
  mkdirSync('.auth', { recursive: true });
  const navigateur = await chromium.launch({ channel: 'chrome' });
  const page = await navigateur.newPage({ baseURL: process.env.E2E_BASE_URL });

  await page.goto('/login');
  await page.fill('input[name="email"]', process.env.E2E_USER_EMAIL);
  await page.fill('input[name="password"]', process.env.E2E_USER_MDP);
  await page.press('input[name="password"]', 'Enter'); // le bouton est animé (jamais « stable ») : on valide au clavier
  await page.waitForURL((url) => !url.pathname.startsWith('/login'), { timeout: 15_000 });

  await page.context().storageState({ path: '.auth/utilisateur.json' });
  await navigateur.close();
}
