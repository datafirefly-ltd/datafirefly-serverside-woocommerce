/**
 * L'acheteur qui n'a pas repondu a la banniere n'est pas un acheteur qui refuse.
 *
 * Le tracker sait deja distinguer trois etats — accorde, refuse, pas de signal
 * lisible — puis ecrase le troisieme en refus. C'est prudent, et ca jette la
 * seule population recuperable : celui qui ignore la banniere, commande, puis
 * accepte sur une page suivante. Sa conversion n'existera jamais.
 *
 * La regle que ce fichier tient :
 *
 *   accorde        on envoie
 *   REFUSE         on jette, tout de suite, quelle que soit la configuration.
 *                  Un refus n'attend pas, ne se garde pas, ne se repousse pas.
 *   pas de reponse on garde DANS SON NAVIGATEUR, a lui, pendant la duree que
 *                  le responsable de traitement a fixee — et rien ne part.
 *                  S'il accepte, on envoie. S'il refuse ou si le delai passe,
 *                  ca disparait.
 *
 * La duree n'a pas de valeur par defaut qui vaille : c'est une decision de
 * conformite, pas un reglage technique. Zero desactive la retenue, et c'est
 * l'etat d'une boutique qui n'a rien decide.
 *
  * Lancer : node tests/test-consent-hold.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

const m = src.match(/\/\/ ---- DFSS_TEST_EXPORT_HOLD_START[\s\S]*?function dfssHoldDecision[\s\S]*?\n\t}\n/);
assert.ok(m, 'dfssHoldDecision introuvable dans le tracker');
const decide = new Function(m[0].replace(/^\t/gm, '') + '\nreturn dfssHoldDecision;')();

const MINUTE = 60_000;
let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

t('consentement accorde : on envoie', () => {
  assert.equal(decide(true, 0, 30 * MINUTE), 'send');
  assert.equal(decide(true, 999 * MINUTE, 0), 'send');
});

t('REFUS : on jette, meme frais, meme si la retenue est active', () => {
  assert.equal(decide(false, 0, 30 * MINUTE), 'discard');
  assert.equal(decide(false, 0, 0), 'discard');
});

t('pas de reponse, retenue desactivee : on jette', () => {
  // Une boutique qui n'a rien decide se comporte comme avant.
  assert.equal(decide(null, 0, 0), 'discard');
});

t('pas de reponse, dans le delai : on garde', () => {
  assert.equal(decide(null, 0, 30 * MINUTE), 'hold');
  assert.equal(decide(null, 29 * MINUTE, 30 * MINUTE), 'hold');
});

t('pas de reponse, delai exactement atteint : on garde encore', () => {
  assert.equal(decide(null, 30 * MINUTE, 30 * MINUTE), 'hold');
});

t('pas de reponse, delai depasse : on jette', () => {
  assert.equal(decide(null, 30 * MINUTE + 1, 30 * MINUTE), 'discard');
});

t('un age absurde ne fait jamais garder', () => {
  for (const age of [-1, NaN, Infinity, null, undefined, 'vieux']) {
    assert.equal(decide(null, age, 30 * MINUTE), 'discard', String(age));
  }
});

t('une duree absurde desactive la retenue', () => {
  for (const d of [-1, NaN, null, undefined, 'longtemps']) {
    assert.equal(decide(null, 0, d), 'discard', String(d));
  }
});

console.log('\n' + ok + ' verifications, toutes vertes');

// ---------------------------------------------------------------------------
// La file elle-meme, pas seulement la decision.
//
// Une decision juste dans une file qui ne se vide jamais ne sert a rien. Ce
// bloc monte un faux navigateur — sessionStorage et rien d'autre — et exerce
// les trois fonctions qui manipulent la file : ecrire, vider, effacer.
// ---------------------------------------------------------------------------

const queueSrc = src.match(/var HOLD_KEY = '_dfss_held';[\s\S]*?\n\treturn ageMs <= holdMs \? 'hold' : 'discard';\n\t}\n/)
  || src.match(/var HOLD_KEY = '_dfss_held';[\s\S]*?function heldFlush\(\) \{[\s\S]*?\n\t}\n/);
assert.ok(queueSrc, 'le bloc de la file est introuvable');

function fauxNavigateur(consentState, holdMinutes) {
  const store = new Map();
  const envoyes = [];
  const ctx = {
    CONSENT: { holdMinutes: holdMinutes },
    marketingConsentState: () => consentState,
    beacon: (n, i, d) => envoyes.push({ n, i, d }),
    dfssHoldDecision: decide,
    window: {
      sessionStorage: {
        getItem: (k) => (store.has(k) ? store.get(k) : null),
        setItem: (k, v) => store.set(k, v),
        removeItem: (k) => store.delete(k),
      },
    },
  };
  const code = queueSrc[0].replace(/^\t/gm, '') +
    '\nreturn { heldPush: heldPush, heldFlush: heldFlush, heldClear: heldClear, heldRead: heldRead };';
  const api = new Function('CONSENT', 'marketingConsentState', 'beacon', 'dfssHoldDecision', 'window', code)(
    ctx.CONSENT, ctx.marketingConsentState, ctx.beacon, ctx.dfssHoldDecision, ctx.window
  );

  return { api, envoyes, store };
}

t('la file se vide quand le visiteur accepte', () => {
  const { api, envoyes, store } = fauxNavigateur(true, 30);
  api.heldPush('purchase', 'order_1', { value: 98.11 });
  api.heldPush('add_to_cart', 'evt_2', {});
  assert.equal(api.heldRead().length, 2, 'les deux doivent etre retenus');

  api.heldFlush();

  assert.equal(envoyes.length, 2, 'les deux doivent partir');
  assert.equal(envoyes[0].n, 'purchase');
  assert.equal(envoyes[0].i, 'order_1');
  assert.deepEqual(envoyes[0].d, { value: 98.11 });
  assert.equal(store.size, 0, 'la file doit disparaitre apres envoi');
});

t('la file est effacee sans rien envoyer quand le visiteur refuse', () => {
  const { api, envoyes, store } = fauxNavigateur(false, 30);
  api.heldPush('purchase', 'order_1', {});

  api.heldFlush();

  assert.equal(envoyes.length, 0, 'un refus n envoie rien');
  assert.equal(store.size, 0, 'et ne garde rien');
});

t('un evenement trop vieux est jete, les autres partent', () => {
  const { api, envoyes } = fauxNavigateur(true, 30);
  api.heldPush('purchase', 'frais', {});
  const list = JSON.parse(Object.values(Object.fromEntries(fauxNavigateur(true, 30).store))[0] || '[]');
  // On vieillit la premiere entree a la main.
  const { api: api2, envoyes: env2, store: st2 } = fauxNavigateur(true, 30);
  api2.heldPush('purchase', 'vieux', {});
  api2.heldPush('purchase', 'frais', {});
  const k = '_dfss_held';
  const rows = JSON.parse(st2.get(k));
  rows[0].t = Date.now() - 31 * 60000;
  st2.set(k, JSON.stringify(rows));

  api2.heldFlush();

  assert.equal(env2.length, 1, 'seul le frais part');
  assert.equal(env2[0].i, 'frais');
  void list; void envoyes;
});

t('la file ne grossit pas sans fin', () => {
  const { api } = fauxNavigateur(null, 30);
  for (let i = 0; i < 50; i++) {
    api.heldPush('page_view', 'e' + i, {});
  }
  assert.ok(api.heldRead().length <= 20, 'plafonnee a 20');
});

console.log('\n' + ok + ' verifications au total, toutes vertes');
