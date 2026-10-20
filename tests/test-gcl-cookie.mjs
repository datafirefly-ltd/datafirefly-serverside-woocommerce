/**
 * L'identifiant de clic garde par la balise Google sert de secours (2.31.0, comme PrestaShop 1.32.0).
 *
 * Nos cookies _dfss_gclid / _dfss_gbraid n'existent que depuis l'installation
 * de l'extension, et seulement si l'identifiant a survecu jusqu'au consentement. La
 * balise Google (gtag.js) ecrit les siens au clic : _gcl_aw (gclid) et _gcl_gb
 * (gbraid), au format "GCL.<horodatage>.<identifiant>". Sans eux, l'achat d'un
 * visiteur venu d'une annonce avant l'installation part sans identifiant de
 * clic, et le dispatcher le saute pour Google.
 *
 * Ce banc eprouve la vraie fonction du traqueur (extraite du fichier livre, pas
 * recopiee) et la vraie collectUserData, avec des cookies simules :
 *   - le notre d'abord, celui de Google ensuite, jamais l'inverse ;
 *   - wbraid n'a pas de cookie Google : pas de secours invente ;
 *   - la fonction PHP jumelle (DFSS_Gads_Log::gcl_cookie_value, lue au checkout) est eprouvee
 *     par tests/test-gads-log.php et tests/test-activity-panel.php.
 *
 * Lancer : node tests/test-gcl-cookie.mjs
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { dirname, join } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const src = readFileSync(join(here, '..', 'assets', 'dfss-tracker.js'), 'utf8');

const gcl = src.match(/\/\/ ---- DFSS_TEST_EXPORT_GCL_START[\s\S]*?\/\/ ---- DFSS_TEST_EXPORT_GCL_END/);
assert.ok(gcl, 'bloc DFSS_TEST_EXPORT_GCL introuvable dans le tracker');
const collect = src.match(/\tfunction collectUserData\(\) \{[\s\S]*?\n\t\}\n/);
assert.ok(collect, 'collectUserData introuvable dans le tracker');

const value = new Function(gcl[0] + '\nreturn dfssGclCookieValue;')();

/** collectUserData du traqueur, avec ces cookies-la. */
function userData(cookies, ga4 = null) {
  const make = new Function('getCookie', 'PUBLIC', gcl[0] + '\n' + collect[0] + '\nreturn collectUserData;');
  return make((name) => (Object.prototype.hasOwnProperty.call(cookies, name) ? cookies[name] : ''), { ga4 })();
}

let ok = 0;
const t = (nom, fn) => { fn(); ok++; console.log('  ok  ' + nom); };

console.log('\ndfssGclCookieValue');
t('GCL.<horodatage>.<identifiant>', () => {
  assert.equal(value('GCL.1760000000.Cj0KCQjw_ab-12'), 'Cj0KCQjw_ab-12');
});
t('le suffixe "$i..." des balises recentes est ignore', () => {
  assert.equal(value('GCL.1760000000.Cj0KCQjw$i1760000001'), 'Cj0KCQjw');
});
t('rien de lisible : chaine vide, jamais une exception', () => {
  for (const raw of ['', null, undefined, 42, {}, '1.1760000000.Cj0K', 'GCL..abc', 'GCL.abc.def']) {
    assert.equal(value(raw), '');
  }
});

console.log('\ncollectUserData');
t('le notre d\'abord', () => {
  const u = userData({ _dfss_gclid: 'OURS', _gcl_aw: 'GCL.1760000000.GOOGLE', _dfss_gbraid: 'OURSB', _gcl_gb: 'GCL.1760000000.GOOGLEB' });
  assert.equal(u.gclid, 'OURS');
  assert.equal(u.gbraid, 'OURSB');
});
t('celui de Google quand le notre manque', () => {
  const u = userData({ _gcl_aw: 'GCL.1760000000.Cj0KCQjwGOOGLE', _gcl_gb: 'GCL.1760000000.0AAAAgoogleB' });
  assert.equal(u.gclid, 'Cj0KCQjwGOOGLE');
  assert.equal(u.gbraid, '0AAAAgoogleB');
});
t('wbraid : pas de cookie Google, pas de secours', () => {
  const u = userData({ _gcl_aw: 'GCL.1760000000.Cj0K', _gcl_dc: 'GCL.1760000000.WB' });
  assert.equal(u.wbraid, undefined);
});
t('aucun cookie : aucun identifiant de clic', () => {
  const u = userData({});
  assert.equal(u.gclid, undefined);
  assert.equal(u.gbraid, undefined);
});
t('un cookie Google illisible ne donne rien', () => {
  const u = userData({ _gcl_aw: 'n importe quoi' });
  assert.equal(u.gclid, undefined);
});

console.log('\n' + ok + ' verifications, toutes vertes');
