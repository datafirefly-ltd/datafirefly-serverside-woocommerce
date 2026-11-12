/**
 * Un evenement doit arriver quel que soit le visiteur : invite ou client connecte, page fraiche
 * ou sortie d'un cache.
 *
 * datafirefly.com, 12/11/2026 : depuis le 05/10, 2 ajouts au panier sur 79 et 0 saisie du paiement
 * sur 17 portaient un compte client, quand 42 a 59 % des pages vues en portaient un. Les etapes
 * declenchees par un clic n'arrivaient donc presque jamais pour un client connecte, et le tunnel
 * d'Insights montrait des achats sans ajout au panier.
 *
 * La cause, prouvee sur un vrai WordPress : WordPress verifie le jeton AVANT le connecteur, contre
 * le visiteur que le cookie de connexion designe. Un jeton qui n'est pas le sien est refuse net
 * (HTTP 403 rest_cookie_invalid_nonce). Or le traceur redemandait un jeton au premier geste SANS
 * presenter le sien : WordPress repondait alors par un jeton de visiteur anonyme, refuse ensuite
 * pour ce client a chaque evenement, et un 403 n'etait jamais retente.
 *
 * Le faux WordPress ci-dessous applique la regle du coeur (rest_cookie_check_errors) : pas de
 * jeton = visiteur anonyme ; jeton du visiteur que le cookie designe = ce visiteur ; tout autre
 * jeton = 403. Il sert aussi la source du coeur pour le jeton d'un compte (admin-ajax rest-nonce),
 * qui ne repond qu'a un client connecte.
 *
 * Lancer : node tests/test-transport.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

const m = src.match(/\/\/ ---- DFSS_TEST_EXPORT_TRANSPORT_START[\s\S]*?\/\/ ---- DFSS_TEST_EXPORT_TRANSPORT_END/);
assert.ok(m, 'dfssTransport introuvable dans le traceur');
const dfssTransport = new Function(m[0].replace(/^\t/gm, '') + '\nreturn dfssTransport;')();

const REST = 'https://shop.test/wp-json/dfss/v1/collect';
const NONCE_URL = 'https://shop.test/wp-json/dfss/v1/nonce';
const ACCOUNT_URL = '/wp-admin/admin-ajax.php?action=rest-nonce';
const NONCE_EVENTS = ['lead', 'complete_registration', 'add_payment_info'];
const CLIENTE = 7;

const tokenOf = (user) => (user ? 'c0mpte' + user + 'abc' : 'v1s1teur00');

/**
 * @param {object} o
 * @param {number} o.visitor      0 = invite, sinon l'identifiant du compte que le cookie designe
 * @param {boolean} o.accountSource  admin-ajax joignable (faux : bloque par un pare-feu)
 * @param {boolean} o.disabled    suivi coupe dans le connecteur
 * @param {number} o.tokenFailures  nombre de demandes de jeton qui echouent d'abord (panne reseau)
 */
function wordpress({ visitor = 0, accountSource = true, disabled = false, tokenFailures = 0 } = {}) {
  const requests = [];
  const accepted = [];
  const answer = (status, body) => ({
    status,
    ok: status >= 200 && status < 300,
    json: async () => (typeof body === 'string' ? JSON.parse(body) : body),
    text: async () => (typeof body === 'string' ? body : JSON.stringify(body)),
  });

  async function fetch(url, init = {}) {
    const headers = init.headers || {};
    const sent = Object.prototype.hasOwnProperty.call(headers, 'X-WP-Nonce') ? headers['X-WP-Nonce'] : null;
    requests.push({ url, method: init.method || 'GET', token: sent });

    if (url === ACCOUNT_URL) {
      return visitor && accountSource ? answer(200, tokenOf(visitor)) : answer(400, '0');
    }
    if (url === NONCE_URL && tokenFailures > 0) {
      tokenFailures--;
      throw new TypeError('Failed to fetch');
    }

    // Le coeur de WordPress, avant toute route.
    let user;
    if (sent === null) {
      user = 0;
    } else if (sent === tokenOf(visitor)) {
      user = visitor;
    } else {
      return answer(403, { code: 'rest_cookie_invalid_nonce', message: 'Cookie check failed', data: { status: 403 } });
    }

    if (url === NONCE_URL) {
      return answer(200, { nonce: tokenOf(user) });
    }
    if (url === REST) {
      if (disabled) {
        return answer(403, { code: 'dfss_disabled', message: 'Tracking disabled', data: { status: 403 } });
      }
      const body = JSON.parse(init.body);
      const events = body.events || [body];
      let ok = false;
      let reason = '';
      for (const e of events) {
        if (sent === null && NONCE_EVENTS.includes(e.event_name)) {
          reason = reason || 'nonce';
          continue;
        }
        accepted.push({ name: e.event_name, id: e.event_id, user });
        ok = true;
      }
      return answer(200, ok || reason === '' ? { ok } : { ok: false, reason });
    }
    throw new Error('adresse inattendue : ' + url);
  }

  return { fetch, requests, accepted };
}

function transport(wp, token, extra = {}) {
  return dfssTransport({
    restUrl: REST,
    nonce: token,
    nonceUrl: NONCE_URL,
    accountNonceUrl: ACCOUNT_URL,
    fetch: wp.fetch,
    sendBeacon: null,
    ...extra,
  });
}

// Les envois s'enchainent en promesses : on attend que plus aucune requete ne parte.
async function settle(wp) {
  let seen = -1;
  while (seen !== wp.requests.length) {
    seen = wp.requests.length;
    for (let i = 0; i < 20; i++) {
      await new Promise((r) => setImmediate(r));
    }
  }
}

const event = (name, id) => JSON.stringify({ event_name: name, event_id: id });
const posts = (wp) => wp.requests.filter((r) => r.url === REST);

let ok = 0;
const t = async (nom, fn) => { await fn(); ok++; console.log('  ok  ' + nom); };

await t('cliente connectee, page fraiche : apres le premier geste, son ajout au panier arrive avec son compte', async () => {
  const wp = wordpress({ visitor: CLIENTE });
  const tr = transport(wp, tokenOf(CLIENTE));
  tr.refresh();
  await settle(wp);
  // Le jeton est redemande EN PRESENTANT le sien : c'est ce qui manquait.
  assert.deepEqual(wp.requests, [{ url: NONCE_URL, method: 'GET', token: tokenOf(CLIENTE) }]);
  assert.equal(tr.token(), tokenOf(CLIENTE));

  tr.post(event('add_to_cart', 'a1'));
  tr.post(event('add_payment_info', 'p1'));
  await settle(wp);
  assert.deepEqual(wp.accepted, [
    { name: 'add_to_cart', id: 'a1', user: CLIENTE },
    { name: 'add_payment_info', id: 'p1', user: CLIENTE },
  ]);
  assert.equal(posts(wp).length, 2, 'un envoi par evenement, aucun renvoi');
});

await t('invite, page fraiche : rien ne change, un envoi par evenement', async () => {
  const wp = wordpress({ visitor: 0 });
  const tr = transport(wp, tokenOf(0));
  tr.refresh();
  await settle(wp);
  assert.equal(wp.requests.length, 1, 'une seule demande de jeton');
  tr.post(event('add_to_cart', 'a1'));
  await settle(wp);
  assert.deepEqual(wp.accepted, [{ name: 'add_to_cart', id: 'a1', user: 0 }]);
  assert.equal(posts(wp).length, 1);
});

await t('invite, page sortie d un cache avec un jeton perime : la page vue refusee est renvoyee une fois', async () => {
  const wp = wordpress({ visitor: 0 });
  const tr = transport(wp, 'per1me0000');
  tr.post(event('page_view', 'v1'));
  await settle(wp);
  assert.deepEqual(wp.accepted, [{ name: 'page_view', id: 'v1', user: 0 }]);
  assert.deepEqual(wp.requests.map((r) => r.method + ' ' + (r.token === null ? 'sans' : r.token)), [
    'POST per1me0000',
    'GET sans',
    'POST ' + tokenOf(0),
  ]);
});

await t('invite, jeton perime : au premier geste, le jeton refuse est remplace par celui d un visiteur', async () => {
  const wp = wordpress({ visitor: 0 });
  const tr = transport(wp, 'per1me0000');
  let got = null;
  tr.refresh((g) => { got = g; });
  await settle(wp);
  assert.equal(got, true);
  assert.equal(tr.token(), tokenOf(0));
  assert.deepEqual(wp.requests.map((r) => r.token), ['per1me0000', null]);
});

await t('cliente connectee sur une page au jeton d un autre (cache) : son compte est retrouve aupres de WordPress', async () => {
  const wp = wordpress({ visitor: CLIENTE });
  const tr = transport(wp, tokenOf(0));
  tr.post(event('add_to_cart', 'a1'));
  await settle(wp);
  assert.deepEqual(wp.accepted, [{ name: 'add_to_cart', id: 'a1', user: CLIENTE }]);
  assert.deepEqual(wp.requests.map((r) => r.url), [REST, NONCE_URL, REST, ACCOUNT_URL, REST]);
  assert.equal(tr.token(), tokenOf(CLIENTE));

  // Le bon jeton est garde : l'evenement suivant part en un seul envoi.
  tr.post(event('add_payment_info', 'p1'));
  await settle(wp);
  assert.equal(posts(wp).length, 4);
  assert.deepEqual(wp.accepted[1], { name: 'add_payment_info', id: 'p1', user: CLIENTE });
});

await t('compte introuvable : rien n est envoye sans jeton a la place d un visiteur que WordPress refuse', async () => {
  // Un membre de l equipe connecte ne doit jamais etre mesure comme un visiteur anonyme.
  const wp = wordpress({ visitor: CLIENTE, accountSource: false });
  const tr = transport(wp, tokenOf(0));
  tr.post(event('add_to_cart', 'a1'));
  await settle(wp);
  assert.deepEqual(wp.accepted, []);
  assert.equal(posts(wp).filter((r) => r.token === null).length, 0, 'aucun envoi sans jeton');
  assert.ok(wp.requests.length <= 4, 'nombre de requetes borne : ' + wp.requests.length);
});

await t('suivi coupe (403 du connecteur) : aucun renvoi, aucune demande de jeton', async () => {
  const wp = wordpress({ visitor: 0, disabled: true });
  const tr = transport(wp, tokenOf(0));
  tr.post(event('page_view', 'v1'));
  await settle(wp);
  assert.equal(wp.requests.length, 1);
});

await t('aucun jeton dans la page : l en-tete n est pas envoye vide, et l evenement qui en exige un est renvoye', async () => {
  const wp = wordpress({ visitor: 0 });
  const tr = transport(wp, '');
  tr.post(event('add_payment_info', 'p1'));
  await settle(wp);
  assert.equal(posts(wp)[0].token, null, 'pas d en-tete X-WP-Nonce vide');
  assert.deepEqual(wp.accepted, [{ name: 'add_payment_info', id: 'p1', user: 0 }]);
  assert.equal(posts(wp).length, 2);
});

await t('un lot refuse est renvoye entier, chaque evenement une seule fois', async () => {
  const wp = wordpress({ visitor: 0 });
  const tr = transport(wp, 'per1me0000');
  tr.post(JSON.stringify({ events: [{ event_name: 'page_view', event_id: 'v1' }, { event_name: 'view_content', event_id: 'c1' }] }));
  await settle(wp);
  assert.deepEqual(wp.accepted.map((e) => e.id), ['v1', 'c1']);
});

await t('panne passagere de la route du jeton : le jeton en main est garde, pas remplace par celui d un visiteur', async () => {
  // Seule la premiere demande echoue : une seconde, sans jeton, aurait repondu.
  const wp = wordpress({ visitor: CLIENTE, tokenFailures: 1 });
  const tr = transport(wp, tokenOf(CLIENTE));
  let got = null;
  tr.refresh((g) => { got = g; });
  await settle(wp);
  assert.equal(got, false);
  assert.equal(tr.token(), tokenOf(CLIENTE));
  assert.equal(wp.requests.length, 1, 'pas de seconde demande apres une panne');
  tr.post(event('add_to_cart', 'a1'));
  await settle(wp);
  assert.deepEqual(wp.accepted, [{ name: 'add_to_cart', id: 'a1', user: CLIENTE }]);
});

await t('envoi qui survit au depart de la page : le jeton voyage dans l adresse, jamais vide', async () => {
  const calls = [];
  const beacon = (url, payload) => { calls.push({ url, payload }); return true; };
  const wp = wordpress({ visitor: 0 });
  assert.equal(transport(wp, 'abc123def4', { sendBeacon: beacon }).beaconNow('{}'), true);
  assert.equal(calls[0].url, REST + '?_wpnonce=abc123def4');
  assert.equal(transport(wp, '', { sendBeacon: beacon }).beaconNow('{}'), true);
  assert.equal(calls[1].url, REST, 'pas de _wpnonce vide');
  assert.equal(transport(wp, 'abc123def4', { sendBeacon: null }).beaconNow('{}'), false);
  assert.equal(transport(wp, 'abc123def4', { sendBeacon: () => { throw new Error('x'); } }).beaconNow('{}'), false);
});

await t('navigateur sans fetch : rien ne casse', async () => {
  const tr = dfssTransport({ restUrl: REST, nonce: 'abc123def4', nonceUrl: NONCE_URL, accountNonceUrl: ACCOUNT_URL, fetch: null, sendBeacon: null });
  let got = null;
  tr.post(event('page_view', 'v1'));
  tr.refresh((g) => { got = g; });
  assert.equal(got, false);
});

console.log('\n' + ok + ' verifications, toutes vertes');
