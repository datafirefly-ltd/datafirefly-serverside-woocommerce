/**
 * The address the Google tags read.
 *
 * Drexco Medical, 05/11/2026: the guest order tracking form put the customer's
 * email in the address, and gtag.js sent it to Google in clear, with or without
 * consent. The vectors are the output of the dispatcher's sanitizeUrl()
 * (tests/fixtures/url-guard-vectors.json), so the browser and the server keep
 * one rule. Two cases differ on purpose: the server drops every fragment, the
 * guard only one that carries an identifier, because an address it does not
 * change must stay untouched.
 *
 * Lancer : node tests/test-url-guard.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';
import vm from 'node:vm';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-url-guard.js'), 'utf8');
const vectors = JSON.parse(readFileSync(join(here, 'fixtures', 'url-guard-vectors.json'), 'utf8'));

function run(href, referrer = '', dataLayer) {
  const window = { location: { href } };
  if (dataLayer) window.dataLayer = dataLayer;
  vm.runInNewContext(src, { window, document: { referrer }, URL });

  return window.dataLayer;
}

const set = (entry) => {
  assert.equal(entry[0], 'set');
  return JSON.parse(JSON.stringify(entry[1]));
};

let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

const DIFFERENT = {
  'https://shop.test/x#jean@exemple.fr': 'https://shop.test/x',
  'https://shop.test/x?a=1#jean%40exemple.fr': 'https://shop.test/x?a=1',
  'https://shop.test/x?a=1&&b=2': null,
};

t('meme verdict que le dispatcher sur ' + vectors.length + ' adresses', () => {
  for (const v of vectors) {
    const expected = v.url in DIFFERENT ? DIFFERENT[v.url] : v.clean;
    const dl = run(v.url);
    if (expected === null) {
      assert.equal(dl, undefined, v.url);
    } else {
      assert.deepEqual(set(dl[0]), { page_location: expected }, v.url);
    }
  }
});

t('l adresse de l essai Drexco garde sa reference et perd l e-mail', () => {
  const dl = run('https://www.drexcomedical.fr/suivi-commande-invite?order_reference=ESSAI&email=controle%40exemple.invalid');
  assert.deepEqual(set(dl[0]), { page_location: 'https://www.drexcomedical.fr/suivi-commande-invite?order_reference=ESSAI' });
});

t('le gclid survit sur une page nettoyee', () => {
  const dl = run('https://s.test/a?email=a%40b.fr&gclid=ABC');
  assert.deepEqual(set(dl[0]), { page_location: 'https://s.test/a?gclid=ABC' });
});

t('un identifiant de clic reste, meme s il ressemble a un telephone', () => {
  assert.equal(run('https://s.test/a?gclid=0612345678&gad_campaignid=0612345678'), undefined);
});

t('la page suivante ne rend pas l e-mail par le referent', () => {
  const dl = run('https://s.test/produit', 'https://s.test/suivi?email=a%40b.fr');
  assert.deepEqual(set(dl[0]), { page_referrer: 'https://s.test/suivi' });
});

t('page et referent nettoyes dans le meme ordre', () => {
  const dl = run('https://s.test/a?email=a%40b.fr', 'https://s.test/b?tel=0612345678');
  assert.deepEqual(set(dl[0]), { page_location: 'https://s.test/a', page_referrer: 'https://s.test/b' });
});

t('une page sans identifiant ne touche pas au dataLayer', () => {
  assert.equal(run('https://s.test/robe?color=rouge#/12-taille-m', 'https://google.com/'), undefined);
});

t('le dataLayer existant est garde, et l entree reste un objet arguments', () => {
  const dl = [['consent', 'default', {}]];
  run('https://s.test/a?email=a%40b.fr', '', dl);
  assert.equal(dl.length, 2);
  assert.equal(Array.isArray(dl[1]), false, 'gtag ignore un tableau : il faut arguments');
  assert.equal(dl[1].length, 2);
});

t('une erreur ne casse jamais la page', () => {
  const window = { get location() { throw new Error('boom'); } };
  vm.runInNewContext(src, { window, document: { referrer: '' }, URL });
});

t('une adresse tres longue reste rapide', () => {
  const debut = Date.now();
  run('https://s.test/' + 'a'.repeat(120000) + '?x=' + '1 '.repeat(40000));
  assert.ok(Date.now() - debut < 500, 'trop lent : ' + (Date.now() - debut) + ' ms');
});

console.log(ok + ' tests');
