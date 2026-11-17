// Sécurité (7.1) : en-têtes de sécurité, cookies de session (HttpOnly, SameSite), déconnexion protégée par un jeton CSRF.
import { test } from '@playwright/test';
import { reinitialiser } from '../lib/etat.js';
import { expect } from '../lib/aide.js';

test.beforeEach(() => reinitialiser());

test('en-têtes de sécurité sur le site, la connexion et l\'administration ; pas de HSTS en HTTP', async ({ request }) => {
  for (const url of ['/', '/login', '/produits', '/admin']) {
    const r = await request.get(url, { maxRedirects: 0 });
    const h = r.headers();
    expect(h['x-content-type-options'], url).toBe('nosniff');
    expect(h['x-frame-options'], url).toBe('SAMEORIGIN');
    expect(h['referrer-policy'], url).toBe('strict-origin-when-cross-origin');
    expect(h['permissions-policy'], url).toContain('camera=()');
    expect(h['strict-transport-security'], url).toBeUndefined(); // HTTP local : jamais de HSTS
  }
});

test.describe('connecté', () => {
  // Session PROPRE à ce test (la déconnexion détruit la session : on ne touche pas à celle des autres tests)
  test.use({ storageState: { cookies: [], origins: [] } });

  test('cookie de session HttpOnly + SameSite=Lax ; déconnexion : le lien porte un jeton, l\'adresse nue est refusée', async ({ page, context }) => {
    await page.goto('/login');
    await page.fill('input[name="email"]', process.env.E2E_USER_EMAIL);
    await page.fill('input[name="password"]', process.env.E2E_USER_MDP);
    await page.press('input[name="password"]', 'Enter');
    await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
    await page.goto('/user/mon-compte');
    const cookies = await context.cookies();
    const session = cookies.find((c) => c.name === 'PHPSESSID');
    expect(session, 'cookie de session').toBeTruthy();
    expect(session.httpOnly).toBe(true);
    expect(session.sameSite).toBe('Lax');

    // Adresse /logout sans jeton : refusée (403), la session reste ouverte
    const nu = await page.request.get('/logout', { maxRedirects: 0 });
    expect(nu.status()).toBe(403);
    await page.goto('/user/mon-compte');
    await expect(page).toHaveURL(/mon-compte/);

    // Le lien de déconnexion de la page contient un jeton valide
    const lien = await page.locator('a.logout, a.logout-item, a[href*="/logout"]').first().getAttribute('href');
    expect(lien).toMatch(/\/logout\?_csrf_token=/);
    await page.goto(lien);
    await page.goto('/user/mon-compte');
    await expect(page).toHaveURL(/login/);
  });
});
