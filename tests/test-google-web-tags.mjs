/**
 * Les deux choses qu'un envoi serveur ne fera jamais.
 *
 * Drexco Medical, 8 septembre 2026 : GTM est desinstalle quand le server-side
 * prend le relais. Il portait aussi deux tags que nous ne remplacions pas.
 *
 *   @Product-Remarketing   les produits vus, pour alimenter les audiences.
 *                          Une audience se construit avec le cookie du
 *                          visiteur : un evenement serveur ne peut pas la
 *                          nourrir. Performance Max et Shopping s'assechent.
 *
 *   @Add-to-cart - GAds    une action de conversion de type PAGE WEB, 44 a 67
 *                          signaux par jour. Un import ne peut pas l'alimenter,
 *                          et le type est immuable apres creation.
 *
 * Ces deux-la repassent donc par le navigateur, et c'est assume. Ce ne sont pas
 * des conversions dupliquees : le remarketing n'en est pas une, et une action
 * de type page web est une action DIFFERENTE de celle que le serveur alimente.
 * Google ne dedoublonne qu'a action egale.
 *
  * Lancer : node tests/test-google-web-tags.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

const m = src.match(/\/\/ ---- DFSS_TEST_EXPORT_GADS_START[\s\S]*?\/\/ ---- DFSS_TEST_EXPORT_GADS_END/);
assert.ok(m, 'le bloc Google Ads navigateur est introuvable');
const api = new Function(
  m[0].replace(/^\t/gm, '') +
  '\nreturn { remarketing: dfssRemarketingPayload, conversion: dfssWebConversionPayload };'
)();

const AW = 'AW-1002283107';
let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

// ---- remarketing ----------------------------------------------------------

t('une fiche produit envoie son identifiant et sa valeur', () => {
  assert.deepEqual(
    api.remarketing(AW, 'view_item', { items: [{ id: 'REF-123', price: 98.11 }], value: 98.11, currency: 'EUR' }),
    { send_to: AW, ecomm_pagetype: 'product', ecomm_prodid: ['REF-123'], ecomm_totalvalue: 98.11 }
  );
});

t('le panier envoie tous ses produits', () => {
  const p = api.remarketing(AW, 'view_cart', { items: [{ id: 'A' }, { id: 'B' }], value: 40 });
  assert.equal(p.ecomm_pagetype, 'cart');
  assert.deepEqual(p.ecomm_prodid, ['A', 'B']);
});

t('la confirmation de commande est un achat', () => {
  assert.equal(api.remarketing(AW, 'purchase', { items: [{ id: 'A' }], value: 98.11 }).ecomm_pagetype, 'purchase');
});

t('une page sans produit reste une page', () => {
  const p = api.remarketing(AW, 'page_view', {});
  assert.equal(p.ecomm_pagetype, 'other');
  assert.equal(p.ecomm_prodid, undefined);
  assert.equal(p.ecomm_totalvalue, undefined);
});

t('sans identifiant de conversion, rien', () => {
  assert.equal(api.remarketing('', 'view_item', { items: [{ id: 'A' }] }), null);
  assert.equal(api.remarketing(null, 'view_item', { items: [{ id: 'A' }] }), null);
});

t('une liste de produits absurde ne casse rien', () => {
  for (const items of [null, 'x', 42, [null], [{}], [{ id: '' }]]) {
    const p = api.remarketing(AW, 'view_item', { items });
    assert.ok(p, String(items));
    assert.equal(p.ecomm_prodid, undefined, String(items));
  }
});

t('la liste est plafonnee, une page de categorie peut etre enorme', () => {
  const items = Array.from({ length: 200 }, (_, i) => ({ id: 'P' + i }));
  assert.equal(api.remarketing(AW, 'view_item_list', { items }).ecomm_prodid.length, 100);
});

// ---- conversions de type page web ----------------------------------------

t('une conversion page web porte son libelle complet', () => {
  assert.deepEqual(
    api.conversion(AW, { add_to_cart: 'uRADCKrineEYEOPA9t0D' }, 'add_to_cart', { value: 40, currency: 'EUR', orderId: 'CMD-9' }),
    { send_to: AW + '/uRADCKrineEYEOPA9t0D', value: 40, currency: 'EUR', transaction_id: 'CMD-9' }
  );
});

t('un evenement non declare n envoie rien', () => {
  assert.equal(api.conversion(AW, { add_to_cart: 'uRAD' }, 'purchase', { value: 1 }), null);
});

t('aucun libelle declare, rien du tout', () => {
  assert.equal(api.conversion(AW, {}, 'add_to_cart', { value: 1 }), null);
  assert.equal(api.conversion(AW, null, 'add_to_cart', { value: 1 }), null);
});

t('sans identifiant de conversion, rien', () => {
  assert.equal(api.conversion('', { add_to_cart: 'uRAD' }, 'add_to_cart', { value: 1 }), null);
});

t('une conversion sans valeur reste valide', () => {
  const p = api.conversion(AW, { add_to_cart: 'uRAD' }, 'add_to_cart', {});
  assert.equal(p.send_to, AW + '/uRAD');
  assert.equal(p.value, undefined);
  assert.equal(p.transaction_id, undefined);
});

console.log('\n' + ok + ' verifications, toutes vertes');
