/**
 * Deux etapes du tunnel etaient mal comptees sur datafirefly.com (releve du 12/11/2026).
 *
 * Debut de commande : depuis le 05/10, 38 evenements sur 127 repetaient le meme panier (meme
 * montant, memes lignes) moins de 30 minutes apres le precedent. La page de commande le renvoyait
 * a chaque chargement, et le meme panier affiche sur la page panier puis sur la page de commande
 * en donnait deux. Il est compte une fois par panier.
 *
 * Saisie du paiement : elle ne partait qu'au choix d'un moyen de paiement ou au clic sur le bouton
 * de commande. Les champs de carte et les boutons de portefeuille (Apple Pay, Google Pay, Link)
 * vivent dans des cadres du prestataire de paiement, dont la page ne voit jamais les clics : une
 * commande payee par un bouton express ne passait donc jamais par cette etape.
 *
 * Lancer : node tests/test-funnel-steps.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

const m = src.match(/\/\/ ---- DFSS_TEST_EXPORT_FUNNEL_START[\s\S]*?\/\/ ---- DFSS_TEST_EXPORT_FUNNEL_END/);
assert.ok(m, 'bloc du tunnel introuvable dans le traceur');
const { dfssCheckoutSignature: signature, dfssOnceGate: gate, dfssIsPaymentFrame: isPaymentFrame, DFSS_PAYMENT_AREAS: areas } =
  new Function(m[0].replace(/^\t/gm, '') + '\nreturn { dfssCheckoutSignature, dfssOnceGate, dfssIsPaymentFrame, DFSS_PAYMENT_AREAS };')();

let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

const panier = { value: 149, currency: 'EUR', products: [{ id: 7292, quantity: 1 }, { id: 81, quantity: 2 }] };

t('le meme panier donne la meme signature, quel que soit l ordre des lignes', () => {
  const inverse = { ...panier, products: [...panier.products].reverse() };
  assert.equal(signature(panier), signature(inverse));
});

t('un panier modifie est un autre debut de commande', () => {
  assert.notEqual(signature(panier), signature({ ...panier, products: [{ id: 7292, quantity: 2 }, { id: 81, quantity: 2 }] }));
  assert.notEqual(signature(panier), signature({ ...panier, products: [{ id: 7292, quantity: 1 }] }));
  assert.notEqual(signature(panier), signature({ ...panier, value: 99 }));
  assert.notEqual(signature(panier), signature({ ...panier, currency: 'USD' }));
});

t('un contexte incomplet ne casse rien', () => {
  assert.equal(typeof signature({}), 'string');
  assert.equal(typeof signature(null), 'string');
  assert.equal(typeof signature({ products: [null] }), 'string');
});

function memoire() {
  const data = new Map();
  return {
    getItem: (k) => (data.has(k) ? data.get(k) : null),
    setItem: (k, v) => { data.set(k, String(v)); },
    removeItem: (k) => { data.delete(k); },
  };
}

t('un panier deja compte ne l est pas une seconde fois dans le meme onglet', () => {
  const g = gate(memoire(), '_dfss_checkout');
  const s = signature(panier);
  assert.equal(g.seen(s), false);
  g.remember(s);
  assert.equal(g.seen(s), true);
  assert.equal(g.seen(signature({ ...panier, value: 99 })), false, 'un autre panier reste a compter');
});

t('une commande passee referme le debut de commande : le meme panier rachete compte a nouveau', () => {
  const g = gate(memoire(), '_dfss_checkout');
  const s = signature(panier);
  g.remember(s);
  g.clear();
  assert.equal(g.seen(s), false);
});

t('memoire refusee (navigation privee) : on compte a chaque fois, jamais une etape perdue', () => {
  const refuse = {
    getItem: () => { throw new Error('SecurityError'); },
    setItem: () => { throw new Error('QuotaExceededError'); },
    removeItem: () => { throw new Error('SecurityError'); },
  };
  for (const storage of [refuse, null, undefined]) {
    const g = gate(storage, '_dfss_checkout');
    g.remember('x');
    g.clear();
    assert.equal(g.seen('x'), false);
  }
});

// Un element reduit a ce que la fonction lit : son nom de balise et ses ancetres.
const element = (tag, ancestors) => ({
  tagName: tag,
  closest: (selector) => {
    assert.equal(selector, areas);
    return ancestors.some((a) => selector.split(',').map((s) => s.trim()).some((s) => matches(a, s))) ? {} : null;
  },
});
function matches(a, s) {
  if (s.startsWith('#')) { return a.id === s.slice(1); }
  if (s.startsWith('.')) { return (a.classes || []).includes(s.slice(1)); }
  const sub = s.match(/^\[id\*="(.+)"\]$/);
  return !!sub && typeof a.id === 'string' && a.id.includes(sub[1]);
}

t('le champ de carte du prestataire, dans le bloc de paiement, compte comme saisie du paiement', () => {
  assert.equal(isPaymentFrame(element('IFRAME', [{ classes: ['wc-stripe-upe-element'] }, { id: 'payment' }])), true);
  assert.equal(isPaymentFrame(element('iframe', [{ classes: ['woocommerce-checkout-payment'] }])), true);
});

t('un bouton de portefeuille (paiement express) compte aussi', () => {
  assert.equal(isPaymentFrame(element('IFRAME', [{ id: 'wc-stripe-express-checkout-element' }])), true);
  assert.equal(isPaymentFrame(element('IFRAME', [{ classes: ['wc-block-components-express-payment'] }])), true);
});

t('la commande par blocs est couverte', () => {
  assert.equal(isPaymentFrame(element('IFRAME', [{ classes: ['wc-block-checkout__payment-method'] }])), true);
});

t('un cadre hors du paiement (video, carte, discussion) ne compte pas', () => {
  assert.equal(isPaymentFrame(element('IFRAME', [{ id: 'footer' }, { classes: ['chat-widget'] }])), false);
});

t('ce qui n est pas un cadre ne compte pas, meme dans le bloc de paiement', () => {
  // La case des conditions generales vit dans #payment : la cocher n'est pas saisir un paiement.
  assert.equal(isPaymentFrame(element('INPUT', [{ id: 'payment' }])), false);
  assert.equal(isPaymentFrame(element('BODY', [])), false);
  assert.equal(isPaymentFrame(null), false);
  assert.equal(isPaymentFrame({ tagName: 'IFRAME' }), false);
  assert.equal(isPaymentFrame({ tagName: 'IFRAME', closest: () => { throw new Error('selecteur'); } }), false);
});

console.log('\n' + ok + ' verifications, toutes vertes');
