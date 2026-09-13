/**
 * Le gclid doit survivre à la navigation quand le visiteur n'a pas encore
 * répondu à la bannière.
 *
 * Drexco Medical, 13/09/2026 : 47 achats sur 55 partaient sans identifiant de
 * clic, donc inattribuables par Google, quoi qu'on lui envoie. La cause n'est
 * pas la capture — elle fonctionne — c'est son MOMENT. `captureClickIds` est
 * derrière `whenConsent`, donc un visiteur qui arrive d'une annonce, navigue,
 * puis accepte a déjà perdu son gclid : il n'était que dans l'URL de la page
 * d'atterrissage.
 *
 * GTM faisait ce travail avec son Conversion Linker et `enableUrlPassthrough`.
 * En le retirant on a retiré ça aussi.
 *
 * Propager l'identifiant dans les liens internes n'est PAS du stockage : c'est
 * un paramètre d'URL, il ne demande pas de consentement. Le cookie, lui, reste
 * derrière le consentement — cette limite ne bouge pas.
 *
  * Lancer : node tests/test-url-passthrough.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

// Le tracker est une IIFE de navigateur. On extrait la seule fonction pure
// qu'on veut éprouver, plutôt que de simuler un DOM entier.
const m = src.match(/\/\/ ---- DFSS_TEST_EXPORT_START[\s\S]*?function dfssDecorateUrl[\s\S]*?\n\t}\n/);
assert.ok(m, 'dfssDecorateUrl introuvable dans le tracker');
const decorate = new Function(m[0].replace(/^\t/gm, '') + '\nreturn dfssDecorateUrl;')();

const ids = { gclid: 'ABC123', gbraid: '', wbraid: '' };
const origin = 'https://www.drexcomedical.fr';

let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

t('ajoute le gclid a un lien interne', () => {
  assert.equal(
    decorate('https://www.drexcomedical.fr/panier', ids, origin),
    'https://www.drexcomedical.fr/panier?gclid=ABC123'
  );
});

t('respecte une query deja presente', () => {
  assert.equal(
    decorate('https://www.drexcomedical.fr/recherche?q=gants', ids, origin),
    'https://www.drexcomedical.fr/recherche?q=gants&gclid=ABC123'
  );
});

t('ne touche jamais un lien externe', () => {
  // Envoyer l'identifiant de clic a un tiers serait une fuite, pas une mesure.
  assert.equal(decorate('https://google.com/x', ids, origin), null);
  assert.equal(decorate('https://paiement.exemple.fr/', ids, origin), null);
});

t('laisse tranquille mailto, tel, javascript et ancres', () => {
  for (const href of ['mailto:a@b.fr', 'tel:+33123456789', 'javascript:void(0)', '#contenu']) {
    assert.equal(decorate(href, ids, origin), null, href);
  }
});

t('ne double jamais un parametre deja la', () => {
  const href = 'https://www.drexcomedical.fr/p?gclid=DEJA';
  assert.equal(decorate(href, ids, origin), null);
});

t('porte gbraid et wbraid comme le gclid', () => {
  assert.equal(
    decorate('https://www.drexcomedical.fr/p', { gclid: '', gbraid: 'GB1', wbraid: '' }, origin),
    'https://www.drexcomedical.fr/p?gbraid=GB1'
  );
  assert.equal(
    decorate('https://www.drexcomedical.fr/p', { gclid: '', gbraid: '', wbraid: 'WB1' }, origin),
    'https://www.drexcomedical.fr/p?wbraid=WB1'
  );
});

t('sans identifiant, ne touche a rien', () => {
  assert.equal(decorate('https://www.drexcomedical.fr/p', { gclid: '', gbraid: '', wbraid: '' }, origin), null);
});

t('un href relatif est resolu sur l origine', () => {
  assert.equal(decorate('/contact', ids, origin), 'https://www.drexcomedical.fr/contact?gclid=ABC123');
});

t('une valeur biscornue ne casse rien', () => {
  assert.equal(decorate('', ids, origin), null);
  assert.equal(decorate(null, ids, origin), null);
  assert.equal(decorate(undefined, ids, origin), null);
  assert.equal(decorate(42, ids, origin), null);
});

t('un href malforme est traite comme un chemin relatif, comme le fait le navigateur', () => {
  // Ce n'est pas une indulgence : <a href="ht!tp://x"> navigue vers un chemin
  // relatif dans tous les navigateurs. On reste donc sur l'origine, et rien ne
  // fuit. La seule chose qui compte ici est qu'on ne sorte pas du site.
  const out = decorate('ht!tp://casse', ids, origin);
  assert.ok(out.startsWith(origin + '/'), out);
  assert.ok(out.includes('gclid=ABC123'), out);
});

console.log('\n' + ok + ' verifications, toutes vertes');
