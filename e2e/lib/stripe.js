// Faux événements Stripe SIGNÉS (même secret que l'application de test) : on teste tout le parcours
// côté site (panier -> clic sur Payer -> commande -> webhook -> stock, e-mail) sans entrer de numéro de carte
// sur la page hébergée par Stripe (ce test-là reste manuel : carte refusée S1).
import crypto from 'node:crypto';

export function construire(type, objet) {
  return JSON.stringify({
    id: `evt_e2e_${crypto.randomBytes(8).toString('hex')}`,
    object: 'event',
    api_version: '2020-08-27',
    created: Math.floor(Date.now() / 1000),
    livemode: false,
    pending_webhooks: 1,
    type,
    data: { object: { object: 'checkout.session', ...objet } },
  });
}

export async function poster(request, payload, secret = process.env.E2E_WHSEC) {
  const t = Math.floor(Date.now() / 1000);
  const v1 = crypto.createHmac('sha256', secret).update(`${t}.${payload}`).digest('hex');
  const reponse = await request.post('/webhook/stripe', {
    data: payload,
    headers: { 'content-type': 'application/json', 'stripe-signature': `t=${t},v1=${v1}` },
  });
  let corps = null;
  let texte = '';
  try { texte = await reponse.text(); corps = JSON.parse(texte); } catch { /* corps non JSON */ }
  if (corps === null) throw new Error(`Webhook : réponse inattendue (HTTP ${reponse.status()}) : ${texte.slice(0, 600)}`);
  return { statut: reponse.status(), corps };
}

/** Événement « paiement confirmé » pour une commande (montant en centimes, modifiable pour simuler un écart). */
export function paiement(commande, { montant, devise = 'eur', statutPaiement = 'paid' } = {}) {
  return construire('checkout.session.completed', {
    id: commande.session,
    client_reference_id: String(commande.id),
    payment_status: statutPaiement,
    payment_intent: intentPaiement(commande),
    currency: devise,
    amount_total: montant ?? Math.round(commande.total * 100),
    customer_email: process.env.E2E_USER_EMAIL,
  });
}

/** Événement « session expirée » pour une commande. */
export function expiration(commande) {
  return construire('checkout.session.expired', {
    id: commande.session,
    client_reference_id: String(commande.id),
    payment_status: 'unpaid',
    currency: 'eur',
    amount_total: Math.round(commande.total * 100),
  });
}

/** Identifiant de paiement Stripe simulé (le même pour le paiement et pour ses remboursements). */
export function intentPaiement(commande) {
  return `pi_e2e_${commande.id}`;
}

/**
 * Événement « remboursement » (fait dans le tableau de bord Stripe) : `rembourse` = total cumulé remboursé en
 * centimes ; remboursement total si rembourse >= montant payé.
 */
export function remboursement(commande, rembourse) {
  const montant = Math.round(commande.total * 100);
  return JSON.stringify({
    id: `evt_e2e_${crypto.randomBytes(8).toString('hex')}`,
    object: 'event',
    api_version: '2020-08-27',
    created: Math.floor(Date.now() / 1000),
    livemode: false,
    pending_webhooks: 1,
    type: 'charge.refunded',
    data: { object: { object: 'charge', id: `ch_e2e_${commande.id}`, payment_intent: intentPaiement(commande), amount: montant, amount_refunded: rembourse, refunded: rembourse >= montant, currency: 'eur' } },
  });
}