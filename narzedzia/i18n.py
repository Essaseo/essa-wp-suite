#!/usr/bin/env python3
"""
Narzędzie językowe wtyczki: zamiana tekstów źródłowych na angielskie
i zbudowanie polskiego tłumaczenia (.po + .mo) bez gettexta w systemie.

  python narzedzia/i18n.py apply  <mapa.json> <katalog-wtyczki>
      Podmienia teksty w wywołaniach __(), _e(), esc_html__() itd.
      Mapa: {"tekst polski": "English text"} — klucze dokładnie jak w kodzie.

  python narzedzia/i18n.py po     <mapa.json> <katalog-wtyczki> [locale] [textdomain]
      Buduje languages/<textdomain>-<locale>.po i .mo (msgid = angielski, msgstr = polski).

  python narzedzia/i18n.py check  <katalog-wtyczki>
      Wypisuje teksty, które zostały po polsku (diakrytyki w msgid).
"""
import io
import json
import os
import re
import struct
import sys

FN = r'(?:__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|esc_js|_x)'
RE_S = re.compile(FN + r"\(\s*'((?:\\.|[^'\\])*)'", re.S)
RE_D = re.compile(FN + r'\(\s*"((?:\\.|[^"\\])*)"', re.S)
RE_N = re.compile(r"_n\(\s*'((?:\\.|[^'\\])*)'\s*,\s*'((?:\\.|[^'\\])*)'", re.S)
PLCHARS = 'ąćęłńóśźżĄĆĘŁŃÓŚŹŻ'


def php_unescape(s):
    """Tekst z kodu PHP (apostrofy) → tekst czysty."""
    return s.replace("\\'", "'").replace('\\\\', '\\')


def php_escape(s):
    """Tekst czysty → bezpieczny w apostrofach PHP."""
    return s.replace('\\', '\\\\').replace("'", "\\'")


def php_files(root):
    for dirpath, _, files in os.walk(root):
        for f in sorted(files):
            if f.endswith('.php'):
                yield os.path.join(dirpath, f)


def cmd_apply(mapping_path, root):
    mapping = json.load(io.open(mapping_path, encoding='utf-8'))
    done, missing, files_changed = 0, set(), 0

    def sub_single(m, quote="'"):
        raw = m.group(1)
        if raw in mapping:
            en = mapping[raw]
            # W stringach w cudzyslowach PHP sam interpretuje sekwencje (nowa linia, dolar),
            # wiec tam nie wolno dokladac escapowania.
            new = php_escape(php_unescape(en)) if quote == "'" else en
            return m.group(0).replace(quote + raw + quote, quote + new + quote, 1)
        if any(c in raw for c in PLCHARS):
            missing.add(raw)
        return m.group(0)

    for path in php_files(root):
        src = io.open(path, encoding='utf-8').read()
        before = src

        def rep_n(m):
            out = m.group(0)
            for g in (m.group(1), m.group(2)):
                if g in mapping:
                    out = out.replace("'" + g + "'", "'" + php_escape(php_unescape(mapping[g])) + "'", 1)
                elif any(c in g for c in PLCHARS):
                    missing.add(g)
            return out

        src = RE_N.sub(rep_n, src)
        src = RE_S.sub(lambda m: sub_single(m, "'"), src)
        src = RE_D.sub(lambda m: sub_single(m, '"'), src)

        if src != before:
            io.open(path, 'w', encoding='utf-8', newline='\n').write(src)
            files_changed += 1
            done += 1
    print(f'apply: zmienionych plikow {files_changed}')
    if missing:
        print(f'UWAGA: {len(missing)} tekstow bez tlumaczenia:')
        for s in sorted(missing)[:25]:
            print('  -', s[:100])
    return 1 if missing else 0


def po_escape(s):
    return s.replace('\\', '\\\\').replace('"', '\\"').replace('\n', '\\n')


def compile_mo(pairs, dest):
    """Minimalny kompilator .mo (format GNU gettext)."""
    items = sorted(pairs.items())
    keys = b'\x00'.join([])  # placeholder
    ids, strs, koffsets, voffsets = b'', b'', [], []
    for k, v in items:
        ids_off = len(ids)
        strs_off = len(strs)
        kb, vb = k.encode('utf-8'), v.encode('utf-8')
        koffsets.append((len(kb), ids_off))
        voffsets.append((len(vb), strs_off))
        ids += kb + b'\x00'
        strs += vb + b'\x00'

    keystart = 7 * 4 + 16 * len(items)
    valuestart = keystart + len(ids)
    offsets = []
    for (klen, koff), (vlen, voff) in zip(koffsets, voffsets):
        offsets.append((klen, koff + keystart, vlen, voff + valuestart))

    output = struct.pack('Iiiiiii', 0x950412de, 0, len(items), 7 * 4, 7 * 4 + len(items) * 8, 0, 0)
    kd, vd = b'', b''
    for klen, koff, vlen, voff in offsets:
        kd += struct.pack('ii', klen, koff)
        vd += struct.pack('ii', vlen, voff)
    output += kd + vd + ids + strs
    with open(dest, 'wb') as fh:
        fh.write(output)
    return len(items)


def cmd_po(mapping_path, root, locale='pl_PL', domain=None):
    mapping = json.load(io.open(mapping_path, encoding='utf-8'))  # {PL_w_kodzie: EN}
    domain = domain or os.path.basename(os.path.normpath(root))
    langs = os.path.join(root, 'languages')
    os.makedirs(langs, exist_ok=True)

    # msgid = angielski (czysty), msgstr = polski (czysty)
    pairs, refs = {}, {}
    for pl_raw, en in mapping.items():
        pl, en_clean = php_unescape(pl_raw), php_unescape(en)
        if en_clean and pl:
            pairs[en_clean] = pl

    # referencje plik:linia dla .po
    for path in php_files(root):
        src = io.open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, root).replace('\\', '/')
        for rx in (RE_S, RE_D):
            for m in rx.finditer(src):
                txt = php_unescape(m.group(1))
                if txt in pairs:
                    line = src.count('\n', 0, m.start()) + 1
                    refs.setdefault(txt, []).append(f'{rel}:{line}')

    po = [
        '# ESSA WP Suite — polskie tlumaczenie',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: ESSA WP Suite\\n"',
        f'"Language: {locale}\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\\n"',
        '',
    ]
    for en in sorted(pairs):
        for r in sorted(set(refs.get(en, [])))[:6]:
            po.append(f'#: {r}')
        po.append(f'msgid "{po_escape(en)}"')
        po.append(f'msgstr "{po_escape(pairs[en])}"')
        po.append('')

    po_path = os.path.join(langs, f'{domain}-{locale}.po')
    io.open(po_path, 'w', encoding='utf-8', newline='\n').write('\n'.join(po))

    pairs_mo = dict(pairs)
    pairs_mo[''] = (f'Project-Id-Version: ESSA WP Suite\nLanguage: {locale}\n'
                    'MIME-Version: 1.0\nContent-Type: text/plain; charset=UTF-8\n'
                    'Content-Transfer-Encoding: 8bit\n'
                    'Plural-Forms: nplurals=3; plural=(n==1 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);\n')
    mo_path = os.path.join(langs, f'{domain}-{locale}.mo')
    n = compile_mo(pairs_mo, mo_path)
    print(f'po: {po_path} ({len(pairs)} hasel)')
    print(f'mo: {mo_path} ({n} wpisow, {os.path.getsize(mo_path)} B)')
    return 0


def cmd_check(root):
    left = {}
    for path in php_files(root):
        src = io.open(path, encoding='utf-8').read()
        rel = os.path.relpath(path, root).replace('\\', '/')
        for rx in (RE_S, RE_D, RE_N):
            for m in rx.finditer(src):
                for g in m.groups():
                    if g and any(c in g for c in PLCHARS):
                        left.setdefault(g, set()).add(rel)
    if not left:
        print('check: wszystkie teksty po angielsku')
        return 0
    print(f'check: {len(left)} tekstow nadal po polsku')
    for s, files in sorted(left.items())[:30]:
        print('  -', s[:90], '|', ', '.join(sorted(files))[:60])
    return 1


if __name__ == '__main__':
    if len(sys.argv) < 3:
        print(__doc__)
        sys.exit(2)
    cmd = sys.argv[1]
    if cmd == 'apply':
        sys.exit(cmd_apply(sys.argv[2], sys.argv[3]))
    if cmd == 'po':
        sys.exit(cmd_po(sys.argv[2], sys.argv[3], sys.argv[4] if len(sys.argv) > 4 else 'pl_PL',
                        sys.argv[5] if len(sys.argv) > 5 else None))
    if cmd == 'check':
        sys.exit(cmd_check(sys.argv[2]))
    print(__doc__)
    sys.exit(2)
