// Chapitre 1.5, bloc 3 : e-mail confirmé à l'inscription, réinitialisation et changement de mot de passe, changement d'e-mail, validation du profil.
import { test } from '@playwright/test';
import { reinitialiser, creerAutreClient } from '../lib/etat.js';
import { executerSql, valeur } from '../lib/db.js';
import { expect, emails, viderEmails } from '../lib/aide.js';

test.use({ storageState: { cookies: [], origins: [] } });
test.beforeEach(async () => { reinitialiser(); executerSql("DELETE FROM user WHERE email LIKE 'nouveau%@perline.test'"); executerSql('DELETE FROM reset_password_request'); await viderEmails(); });

const MDP = process.env.E2E_USER_MDP;

async function messages() {
  const liste = await emails();
  return Promise.all(liste.map(async (m) => await (await fetch(`${process.env.E2E_MAILPIT_API}/api/v1/message/${m.ID}`)).json()));
}
async function attendreMessage(destinataire) {
  let trouve;
  await expect.poll(async () => { trouve = (await messages()).find((m) => m.To.some((t) => t.Address === destinataire)); return Boolean(trouve); }, { timeout: 15_000 }).toBe(true);
  return trouve;
}
const lienDans = (message, motif) => (message.HTML.match(new RegExp(`href="([^"]*${motif}[^"]*)"`)) ?? [])[1]?.replaceAll('&amp;', '&');

async function connecter(page, email, mdp) {
  await page.goto('/login');
  await page.fill('input[name="email"]', email);
  await page.fill('input[name="password"]', mdp);
  await page.press('input[name="password"]', 'Enter');
}

async function inscrire(page, email) {
  await page.goto('/register');
  await page.fill('[name="registration_form[Nom]"]', 'Nouveau');
  await page.fill('[name="registration_form[Prenom]"]', 'Client');
  await page.fill('[name="registration_form[email]"]', email);
  await page.fill('[name="registration_form[Telephone]"]', '0612345678');
  await page.fill('[name="registration_form[plainPassword][first]"]', MDP);
  await page.fill('[name="registration_form[plainPassword][second]"]', MDP);
  await page.press('[name="registration_form[plainPassword][second]"]', 'Enter');
}

test('inscription : connexion refusée tant que l\'e-mail n\'est pas confirmé, puis acceptée après le lien', async ({ page }) => {
  await inscrire(page, 'nouveau1@perline.test');
  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('body')).toContainText('e-mail de confirmation');
  expect(valeur("SELECT email_verifie FROM user WHERE email = 'nouveau1@perline.test'")).toBe('0');

  await connecter(page, 'nouveau1@perline.test', MDP);
  await expect(page.locator('body')).toContainText('pas encore confirmée');

  const mail = await attendreMessage('nouveau1@perline.test');
  expect(mail.Subject).toContain('Confirmez votre adresse');
  const lien = lienDans(mail, 'verification-email');
  expect(lien).toBeTruthy();

  // Lien falsifié ou expiré : refusé
  const falsifie = lien.replace(/email=[^&]*/, 'email=pirate%40perline.test');
  await page.goto(falsifie);
  await expect(page.locator('body')).toContainText('invalide ou a expiré');
  expect(valeur("SELECT email_verifie FROM user WHERE email = 'nouveau1@perline.test'")).toBe('0');

  await page.goto(lien);
  await expect(page).toHaveURL(/\/login/);
  await expect(page.locator('body')).toContainText('confirmée');
  expect(valeur("SELECT email_verifie FROM user WHERE email = 'nouveau1@perline.test'")).toBe('1');

  await connecter(page, 'nouveau1@perline.test', MDP);
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });
});

test('renvoi du lien : même réponse pour une adresse inconnue, aucun e-mail envoyé', async ({ page }) => {
  await page.goto('/verification-email/renvoyer');
  await page.fill('input[name="email"]', 'inconnu@perline.test');
  await page.click('button[type="submit"]');
  await expect(page.locator('body')).toContainText('Si un compte non confirmé existe');
  expect((await emails()).length).toBe(0);
});

test('mot de passe oublié : e-mail en français envoyé par la boutique, lien à usage unique, pas de fuite sur l\'existence du compte', async ({ page }) => {
  creerAutreClient();
  await page.goto('/reset-password');
  await page.fill('[name="reset_password_request_form[email]"]', 'autre@perline.test');
  await page.press('input[name$="[email]"], input[name$="[second]"]', 'Enter');

  const mail = await attendreMessage('autre@perline.test');
  expect(mail.Subject).toContain('Réinitialisation');
  expect(mail.From.Address).toBe('noreply@exemple.invalid'); // expéditeur pris dans config/boutique.yaml
  expect(mail.HTML).toContain('Choisir un nouveau mot de passe');
  expect(mail.HTML).not.toContain('Hi!');
  const lien = lienDans(mail, 'reset-password/reset');

  await page.goto(lien);
  await page.waitForURL(/reset-password\/reset/);
  await page.fill('[name="change_password_form[plainPassword][first]"]', 'Nouveau-Mdp-2026');
  await page.fill('[name="change_password_form[plainPassword][second]"]', 'Nouveau-Mdp-2026');
  await page.press('input[name$="[email]"], input[name$="[second]"]', 'Enter');
  await expect(page).toHaveURL(/\/login/);

  await connecter(page, 'autre@perline.test', 'Nouveau-Mdp-2026');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'), { timeout: 15_000 });

  // Le lien ne sert qu'une fois
  await page.context().clearCookies();
  await page.goto(lien);
  await expect(page).toHaveURL(/reset-password/);
  await expect(page.locator('body')).not.toContainText('Choisir un nouveau');

  // Adresse inconnue : même page de confirmation, aucun e-mail
  await viderEmails();
  await page.goto('/reset-password');
  await page.fill('[name="reset_password_request_form[email]"]', 'inconnu@perline.test');
  await page.press('input[name$="[email]"], input[name$="[second]"]', 'Enter');
  await expect(page).toHaveURL(/check-email/);
  expect((await emails()).length).toBe(0);
});

test('changement de mot de passe depuis le compte : ancien mot de passe exigé, règles, e-mail de notification', async ({ page }) => {
  creerAutreClient();
  await connecter(page, 'autre@perline.test', MDP);
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));
  await page.goto('/compte/mot-de-passe');

  const remplir = async (actuel, nouveau) => {
    await page.fill('[name="changer_mot_de_passe[motDePasseActuel]"]', actuel);
    await page.fill('[name="changer_mot_de_passe[nouveauMotDePasse][first]"]', nouveau);
    await page.fill('[name="changer_mot_de_passe[nouveauMotDePasse][second]"]', nouveau);
    await page.click('button[type="submit"]');
  };
  const hash = () => valeur("SELECT password FROM user WHERE email = 'autre@perline.test'");
  const avant = hash();

  await remplir('mauvais-mot-de-passe', 'Valide-Mdp-2026'); // ancien mot de passe incorrect
  await expect(page.locator('body')).toContainText('mot de passe actuel est incorrect');
  await remplir(MDP, 'faible'); // règles non respectées
  await expect(page.locator('body')).toContainText('au moins 8 caractères');
  expect(hash()).toBe(avant);

  await remplir(MDP, 'Valide-Mdp-2026');
  await expect(page.locator('body')).toContainText('mot de passe a été modifié');
  expect(hash()).not.toBe(avant);
  const mail = await attendreMessage('autre@perline.test');
  expect(mail.Subject).toContain('mot de passe a été modifié');

  await page.context().clearCookies();
  await connecter(page, 'autre@perline.test', 'Valide-Mdp-2026');
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));
});

test('changement d\'adresse e-mail : mot de passe exigé, ancienne adresse valable jusqu\'à la confirmation de la nouvelle', async ({ page }) => {
  creerAutreClient();
  await connecter(page, 'autre@perline.test', MDP);
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));
  await page.goto('/compte/email');

  await page.fill('[name="changer_email[nouvelEmail]"]', 'nouveau2@perline.test');
  await page.fill('[name="changer_email[motDePasseActuel]"]', 'faux');
  await page.click('button[type="submit"]');
  await expect(page.locator('body')).toContainText('mot de passe est incorrect');
  expect(valeur("SELECT COUNT(*) FROM user WHERE email = 'autre@perline.test'")).toBe('1');

  await page.fill('[name="changer_email[nouvelEmail]"]', 'nouveau2@perline.test');
  await page.fill('[name="changer_email[motDePasseActuel]"]', MDP);
  await page.click('button[type="submit"]');
  await expect(page.locator('body')).toContainText('e-mail de confirmation vient d\'être envoyé');
  expect(valeur("SELECT email FROM user WHERE id = (SELECT id FROM (SELECT id FROM user WHERE email_en_attente = 'nouveau2@perline.test') t)")).toBe('autre@perline.test');

  const mail = await attendreMessage('nouveau2@perline.test');
  const lien = lienDans(mail, 'verification-email');
  await viderEmails();
  await page.goto(lien); // confirmation (connecté) : l'adresse change, déconnexion, notification à l'ancienne adresse
  expect(valeur("SELECT COUNT(*) FROM user WHERE email = 'nouveau2@perline.test'")).toBe('1');
  expect(valeur("SELECT COUNT(*) FROM user WHERE email = 'autre@perline.test'")).toBe('0');
  const notif = await attendreMessage('autre@perline.test');
  expect(notif.Subject).toContain('adresse e-mail a été modifiée');

  await page.context().clearCookies();
  await connecter(page, 'nouveau2@perline.test', MDP);
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));

  // Le lien ne marche qu'une fois
  await page.goto(lien);
  await expect(page.locator('body')).toContainText('plus valable');
});

test('profil : validation côté serveur et jeton CSRF obligatoire', async ({ page }) => {
  creerAutreClient();
  await connecter(page, 'autre@perline.test', MDP);
  await page.waitForURL((u) => !u.pathname.startsWith('/login'));
  await page.goto('/user/mon-compte');
  const jeton = await page.evaluate(() => (document.documentElement.innerHTML.match(/'X-CSRF-TOKEN': '([^']+)'/) ?? [])[1]);
  expect(jeton).toBeTruthy();
  const publier = (donnees, entetes = {}) => page.request.post('/user/mon-compte/update-profil', { data: donnees, headers: { 'X-CSRF-TOKEN': jeton, ...entetes } });

  // Sans jeton ou avec un faux jeton : 403
  expect((await publier({ nom: 'X', prenom: 'Y' }, { 'X-CSRF-TOKEN': '' })).status()).toBe(403);
  expect((await publier({ nom: 'X', prenom: 'Y' }, { 'X-CSRF-TOKEN': 'faux' })).status()).toBe(403);

  // Données invalides : 422, rien n'est enregistré
  const refus = await publier({ nom: '<b>Pirate</b>', prenom: 'Y', telephone: '123', dateNaissance: '2999-01-01' });
  expect(refus.status()).toBe(422);
  const corps = await refus.json();
  expect(Object.keys(corps.errors).sort()).toEqual(['dateNaissance', 'nom', 'telephone']);
  expect(valeur("SELECT nom FROM user WHERE email = 'autre@perline.test'")).toBe('Autre');

  // Données valides : enregistrées
  const ok = await publier({ nom: 'Martin', prenom: 'Léa', telephone: '06 98 76 54 32', dateNaissance: '1990-05-17' });
  expect(ok.status()).toBe(200);
  expect(valeur("SELECT CONCAT(nom, '/', telephone, '/', date_naissance) FROM user WHERE email = 'autre@perline.test'")).toBe('Martin/06 98 76 54 32/1990-05-17');
});
