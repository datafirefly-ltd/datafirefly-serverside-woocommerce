#!/usr/bin/env python3
"""Compile languages/*.po into .mo (GNU format, no hash table), as the shipped files are.

Calibrated on 07/11/2026: it reproduces byte for byte the 8 .mo of 2.31.1. Run from the plugin
folder after editing a .po:  python3 tests/build-mo.py   then  php tests/test-translations.php
"""
import ast, struct, sys

def parse_po(path):
    entries, cur, key = [], {}, None
    def flush():
        nonlocal cur
        if 'msgid' in cur and not cur.get('obsolete'):
            entries.append((cur.get('msgctxt'), cur['msgid'], cur.get('msgid_plural'), cur.get('msgstr', {}), 'fuzzy' in cur.get('flags', '')))
        cur = {}
    for raw in open(path, encoding='utf-8'):
        line = raw.rstrip('\n')
        if not line.strip():
            flush(); key = None; continue
        if line.startswith('#,'):
            cur['flags'] = line; continue
        if line.startswith('#~'):
            cur['obsolete'] = True; continue
        if line.startswith('#'):
            continue
        if line.startswith('"'):
            val = ast.literal_eval(line)
            if isinstance(key, tuple):
                cur['msgstr'][key[1]] += val
            else:
                cur[key] += val
            continue
        word, _, rest = line.partition(' ')
        val = ast.literal_eval(rest)
        if word.startswith('msgstr['):
            n = int(word[7:-1]); cur.setdefault('msgstr', {})[n] = val; key = ('msgstr', n)
        elif word == 'msgstr':
            cur.setdefault('msgstr', {})[0] = val; key = ('msgstr', 0)
        else:
            if word in cur and word == 'msgid':
                flush()
            cur[word] = val; key = word
    flush()
    return entries

def write_mo(entries, path):
    pairs = []
    for ctxt, mid, plural, strs, fuzzy in entries:
        if fuzzy and mid != '':
            continue
        vals = [strs[k] for k in sorted(strs)]
        if not any(vals) and mid != '':
            continue
        k = (ctxt + '\x04' if ctxt else '') + mid + (('\x00' + plural) if plural else '')
        pairs.append((k.encode('utf-8'), '\x00'.join(vals).encode('utf-8')))
    pairs.sort(key=lambda p: p[0])
    n = len(pairs)
    ko, to = 28, 28 + n * 8
    data = b''
    base = 28 + n * 16
    koff, voff = [], []
    for k, _ in pairs:
        koff.append((len(k), base + len(data))); data += k + b'\x00'
    for _, v in pairs:
        voff.append((len(v), base + len(data))); data += v + b'\x00'
    out = struct.pack('<7I', 0x950412de, 0, n, ko, to, 0, 0)
    out += b''.join(struct.pack('<2I', *x) for x in koff) + b''.join(struct.pack('<2I', *x) for x in voff) + data
    open(path, 'wb').write(out)
    return n

if __name__ == '__main__':
    import glob, os
    root = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
    for po in sorted(glob.glob(os.path.join(root, 'languages', '*.po'))):
        n = write_mo(parse_po(po), po[:-3] + '.mo')
        print(os.path.basename(po)[:-3] + '.mo', n, 'entries')
