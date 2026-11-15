/**
 * L'identifiant GA4 de la boutique, quand celui de Google manque.
 *
 * GA4 veut un identifiant sur chaque evenement. Il vient du cookie _ga, que la
 * balise Google ecrit. Un visiteur dont le navigateur ne charge pas cette balise
 * (bloqueur de contenu, filtrage DNS) n'en a pas : l'evenement partait sans
 * identifiant, et GA4 comptait un nouvel utilisateur a chaque page vue. Le
 * traqueur fabrique donc le sien, une fois, et le garde dans le cookie _dfss_cid.
 *
 * Ce cookie s'ecrit sur l'appareil du visiteur : chaque refus ci-dessous est une
 * condition de la decision du 15/11/2026, pas une optimisation.
 *
 * Ce banc eprouve le vrai bloc et la vraie collectUserData du traqueur (extraits
 * du fichier livre, pas recopies). La regle elle-meme est eprouvee en detail dans
 * le depot du service (tests/clientIdShared.test.ts), d'ou le bloc est recopie.
 *
 * Lancer : node tests/test-client-id.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

const cid = src.match(/\t\/\/ ---- DFSS-CLIENT-ID:BEGIN[\s\S]*?\/\/ ---- DFSS-CLIENT-ID:END[^\n]*/);
assert.ok(cid, 'bloc DFSS-CLIENT-ID introuvable dans le tracker');
const glue = src.match(/\tfunction dfssClientId\(\) \{[\s\S]*?\n\t\}\n/);
assert.ok(glue, 'dfssClientId introuvable dans le tracker');
const collect = src.match(/\tfunction collectUserData\(\) \{[\s\S]*?\n\t\}\n/);
assert.ok(collect, 'collectUserData introuvable dans le tracker');

/** collectUserData du traqueur sur un appareil qui a ces cookies, avec ce consentement. */
function visite(cookies, consent) {
  const pot = { ...cookies };
  const ecrits = [];
  const make = new Function(
    'getCookie', 'setCookie', 'hasMarketingConsent', 'marketingConsentState', 'analyticsConsentState', 'PUBLIC', 'dfssGclCookieValue',
    cid[0] + '\n' + glue[0] + '\n' + collect[0] + '\nreturn collectUserData;'
  );
  const u = make(
    (nom) => (Object.prototype.hasOwnProperty.call(pot, nom) ? pot[nom] : ''),
    (nom, valeur, jours) => { ecrits.push([nom, valeur, jours]); if (jours < 0) { delete pot[nom]; } else { pot[nom] = valeur; } },
    () => consent.publicite === true,
    () => consent.publicite,
    () => consent.audience,
    {},
    () => ''
  )();
  return { u, pot, ecrits };
}

const ACCEPTE = { publicite: true, audience: true };
const NOTRE = '2718281828.1763197200';

let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

console.log('\nidentifiant GA4 de la boutique');
t('le visiteur a deja le cookie de Google et pas le notre : celui de Google part, rien n\'est ecrit', () => {
  const { u, ecrits } = visite({ _ga: 'GA1.2.1234567890.1700000000' }, ACCEPTE);
  assert.equal(u.clientId, '1234567890.1700000000');
  assert.deepEqual(ecrits, []);
});
t('sans cookie Google : le traqueur fabrique le sien, une fois, pour 90 jours', () => {
  const premiere = visite({}, ACCEPTE);
  assert.match(premiere.u.clientId, /^[1-9]\d{0,9}\.\d{10}$/);
  assert.deepEqual(premiere.ecrits, [['_dfss_cid', premiere.u.clientId, 90]]);
  const suivante = visite(premiere.pot, ACCEPTE);
  assert.equal(suivante.u.clientId, premiere.u.clientId);
  assert.deepEqual(suivante.ecrits, []);
});
t('une fois le notre cree, il est garde meme si Google ecrit ensuite son cookie : un visiteur, un identifiant', () => {
  const premiere = visite({}, ACCEPTE);
  const suivante = visite({ ...premiere.pot, _ga: 'GA1.1.1234567890.1700000000' }, ACCEPTE);
  assert.equal(suivante.u.clientId, premiere.u.clientId);
  assert.deepEqual(suivante.ecrits, []);
});
t('deux visiteurs n\'ont pas le meme identifiant', () => {
  const vus = new Set();
  for (let i = 0; i < 50; i++) { vus.add(visite({}, ACCEPTE).u.clientId.split('.')[0]); }
  assert.ok(vus.size > 45, 'tirages trop peu varies : ' + vus.size);
});
t('avant l\'accord a la publicite : rien n\'est ecrit ni envoye, le cookie n\'est meme pas lu', () => {
  const sans = visite({}, { publicite: null, audience: null });
  assert.equal(sans.u.clientId, undefined);
  assert.deepEqual(sans.ecrits, []);
  const avec = visite({ _dfss_cid: NOTRE }, { publicite: null, audience: null });
  assert.equal(avec.u.clientId, undefined);
  assert.deepEqual(avec.ecrits, []);
  assert.equal(avec.pot._dfss_cid, NOTRE);
});
t('mesure d\'audience refusee : rien, et le cookie deja pose est supprime', () => {
  const neuf = visite({}, { publicite: true, audience: false });
  assert.equal(neuf.u.clientId, undefined);
  assert.deepEqual(neuf.ecrits, []);
  const connu = visite({ _dfss_cid: NOTRE }, { publicite: true, audience: false });
  assert.equal(connu.u.clientId, undefined);
  assert.deepEqual(connu.ecrits, [['_dfss_cid', '', -1]]);
});
t('publicite refusee : le cookie deja pose est supprime', () => {
  const { u, ecrits } = visite({ _dfss_cid: NOTRE }, { publicite: false, audience: null });
  assert.equal(u.clientId, undefined);
  assert.deepEqual(ecrits, [['_dfss_cid', '', -1]]);
});
t('l\'outil de consentement ne distingue pas la mesure d\'audience : l\'accord a la publicite suffit', () => {
  const { u } = visite({}, { publicite: true, audience: null });
  assert.match(u.clientId, /^[1-9]\d{0,9}\.\d{10}$/);
});
t('un cookie qui n\'est pas le notre est remplace', () => {
  const { u, ecrits } = visite({ _dfss_cid: 'GA1.1.1.1763197200' }, ACCEPTE);
  assert.match(u.clientId, /^[1-9]\d{0,9}\.\d{10}$/);
  assert.notEqual(u.clientId, 'GA1.1.1.1763197200');
  assert.equal(ecrits.length, 1);
});

console.log(`\n${ok} verifications reussies.`);
