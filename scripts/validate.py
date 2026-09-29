#!/usr/bin/env python3
"""custom-sales_stats 정적 검증기 (PHP 테스트 하네스 없이도 실행 가능)

    python3 scripts/validate.py [--css path/to/components.css]

검사 항목
1. 저장소의 모든 JSON 파싱
2. 레이아웃: partial 해석, partial 파일 규칙(meta.is_partial, data_sources/computed 금지)
3. 표현식: {{ }} 짝, ?. / ?? 사용 금지, 코어 SafeLayoutExpressions 금지 토큰, node 로 JS 문법 파싱
4. className: sirsoft-admin_basic 빌드 CSS 에 있는 클래스만 (표현식 안의 문자열 리터럴 포함)
5. admin-page-content-responsive 래퍼에 폭 유틸리티 금지
6. iteration 과 같은 노드의 if 가 item_var 를 참조하지 않는지
7. data_sources endpoint 는 이 모듈 API 만, 핸들러는 허용 목록만
8. $t:custom-sales_stats.* 키와 module.js 의 js.* 키가 ko/en 에 모두 있는지, ko/en 키 구조가 같은지
9. routes/admin.json 의 layout 이 존재하는지
"""
import json
import os
import re
import subprocess
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MOD = 'custom-sales_stats'
LAYOUT_DIR = os.path.join(ROOT, 'resources', 'layouts', 'admin')
errors = []
warnings = []


def err(msg):
    errors.append(msg)


def load_json(path):
    with open(path, encoding='utf-8') as fh:
        return json.load(fh)


def css_classes(argv):
    if '--css' in argv:
        css = open(argv[argv.index('--css') + 1], encoding='utf-8').read()
        out = set()
        for m in re.finditer(r'\.((?:\\.|[A-Za-z0-9_-])+)', css):
            out.add(m.group(1).replace('\\', ''))
        return out
    path = os.path.join(ROOT, 'tests', 'fixtures', 'admin_basic_classes.txt')
    return {ln.strip() for ln in open(path, encoding='utf-8') if ln.strip() and not ln.startswith('#')}


def flatten_keys(d, prefix=''):
    out = set()
    for k, v in d.items():
        key = prefix + k
        if isinstance(v, dict):
            out |= flatten_keys(v, key + '.')
        else:
            out.add(key)
    return out


# ───── 1. JSON ─────
all_json = []
for base, dirs, files in os.walk(ROOT):
    if '.git' in base or 'node_modules' in base or 'vendor' in base:
        continue
    for f in files:
        if f.endswith('.json'):
            p = os.path.join(base, f)
            try:
                all_json.append((p, load_json(p)))
            except Exception as ex:  # noqa: BLE001
                err('JSON 파싱 실패: %s (%s)' % (os.path.relpath(p, ROOT), ex))

# ───── 2. 레이아웃 해석 ─────
def resolve(node, base_dir, rel, depth=0):
    if depth > 10:
        err('partial 중첩이 너무 깊음: ' + rel)
        return node
    if isinstance(node, dict):
        if set(node.keys()) == {'partial'}:
            p = os.path.normpath(os.path.join(base_dir, node['partial']))
            if not p.startswith(LAYOUT_DIR):
                err('partial 경로가 layouts 밖: ' + node['partial'])
                return {}
            if not os.path.exists(p):
                err('partial 파일 없음: %s (in %s)' % (node['partial'], rel))
                return {}
            data = load_json(p)
            prel = os.path.relpath(p, ROOT)
            if not (isinstance(data.get('meta'), dict) and data['meta'].get('is_partial') is True):
                err('partial 에 meta.is_partial:true 없음: ' + prel)
            for bad in ('data_sources', 'computed', 'extends', 'slots'):
                if bad in data:
                    err('partial 에 %s 금지: %s' % (bad, prel))
            data = {k: v for k, v in data.items() if k != 'meta'}
            return resolve(data, os.path.dirname(p), prel, depth + 1)
        return {k: resolve(v, base_dir, rel, depth) for k, v in node.items()}
    if isinstance(node, list):
        return [resolve(v, base_dir, rel, depth) for v in node]
    return node


main_layouts = {}
for f in sorted(os.listdir(LAYOUT_DIR)):
    if f.endswith('.json'):
        p = os.path.join(LAYOUT_DIR, f)
        main_layouts[f[:-5]] = resolve(load_json(p), LAYOUT_DIR, os.path.relpath(p, ROOT))

EXPR_RE = re.compile(r'\{\{(.*?)\}\}', re.S)
DANGEROUS = [r'\.\s*(constructor|__proto__|prototype)\b', r'\[\s*[\'"](constructor|__proto__|prototype)[\'"]\s*\]',
             r'\b(getPrototypeOf|setPrototypeOf|getOwnPropertyDescriptors?|defineProperty|defineProperties)\s*\(',
             r'\bFunction\s*\(', r'\beval\s*\(', r'\bimport\s*\(', r'\b__proto__\b', r'\b__(lookup|define)(Getter|Setter)__\b']
STR_RE = re.compile(r"'([^'\\]*(?:\\.[^'\\]*)*)'|\"([^\"\\]*(?:\\.[^\"\\]*)*)\"")
KNOWN_HANDLERS = {'navigate', 'sequence', 'refetchDataSource', 'downloadAttachment', 'toast', 'setState',
                  MOD + '.openMember', MOD + '.composeNote'}
WIDTH_UTIL = re.compile(r'^(max-w-|w-|mx-auto$|min-w-)')

classes = css_classes(sys.argv)
expressions = []
t_keys = set()


def strip_quotes(expr):
    return STR_RE.sub("''", expr)


def check_string(s, where):
    if s.count('{{') != s.count('}}'):
        err('{{ }} 짝이 맞지 않음 @ %s: %s' % (where, s[:80]))
    for m in re.finditer(r'\$t:(?:defer:)?' + re.escape(MOD) + r'\.([A-Za-z0-9_.]+)', s):
        t_keys.add(m.group(1))
    for pat in DANGEROUS:
        if re.search(pat, s):
            err('위험 표현식 토큰 @ %s: %s' % (where, s[:80]))
    for m in EXPR_RE.finditer(s):
        expr = m.group(1)
        bare = strip_quotes(expr)
        if '?.' in bare:
            err('?. 사용 금지 @ %s: %s' % (where, expr[:100]))
        if '??' in bare:
            err('?? 사용 금지 @ %s: %s' % (where, expr[:100]))
        expressions.append((where, expr))


def check_classes(value, where):
    tokens = []
    if '{{' in value:
        for m in EXPR_RE.finditer(value):
            ex = m.group(1)
            for sm in STR_RE.finditer(ex):
                before = ex[:sm.start()].rstrip()
                if before.endswith(('==', '!=', '===', '!==')) or ex[sm.end():].lstrip(' )').startswith(('==', '!=')):
                    continue  # 비교 대상 문자열 (클래스 아님)
                tokens += (sm.group(1) or sm.group(2) or '').split()
        tokens += EXPR_RE.sub(' ', value).split()
    else:
        tokens = value.split()
    for tok in tokens:
        if tok not in classes:
            err('CSS 클래스 없음 (sirsoft-admin_basic): "%s" @ %s' % (tok, where))


def walk(node, where, item_vars=()):
    if isinstance(node, dict):
        nid = node.get('id') or node.get('name') or ''
        here = where + '/' + str(nid) if nid else where
        it = node.get('iteration')
        if isinstance(it, dict):
            var = it.get('item_var')
            if var and isinstance(node.get('if'), str) and re.search(r'\b' + re.escape(var) + r'\b', node['if']):
                err('iteration 과 같은 노드의 if 가 item_var(%s) 를 참조 @ %s' % (var, here))
        props = node.get('props') if isinstance(node.get('props'), dict) else {}
        cls = props.get('className')
        if isinstance(cls, str):
            check_classes(cls, here)
            if 'admin-page-content-responsive' in cls.split():
                for tok in cls.split():
                    if WIDTH_UTIL.match(tok):
                        err('admin-page-content-responsive 래퍼에 폭 유틸리티 금지: %s @ %s' % (tok, here))
        for key in ('actions',):
            for a in node.get(key) or []:
                check_action(a, here)
        for k, v in node.items():
            if isinstance(v, str):
                check_string(v, here + '.' + k)
            else:
                walk(v, here)
    elif isinstance(node, list):
        for i, v in enumerate(node):
            walk(v, where)


def check_action(a, where):
    if not isinstance(a, dict):
        return
    h = a.get('handler')
    if h and h not in KNOWN_HANDLERS:
        err('알 수 없는 핸들러 %s @ %s' % (h, where))
    if h == 'navigate' and not (a.get('params') or {}).get('path'):
        err('navigate 에 params.path 없음 @ %s' % where)
    for sub in a.get('actions') or []:
        check_action(sub, where)


for name, layout in main_layouts.items():
    where = name
    if layout.get('extends') != '_admin_base':
        err('%s: extends 가 _admin_base 가 아님' % name)
    if MOD + '.stats.view' not in (layout.get('permissions') or []):
        err('%s: permissions 에 %s.stats.view 없음' % (name, MOD))
    ids = set()
    for ds in layout.get('data_sources') or []:
        if ds['id'] in ids:
            err('%s: data_source id 중복 %s' % (name, ds['id']))
        ids.add(ds['id'])
        if not ds.get('endpoint', '').startswith('/api/modules/' + MOD + '/'):
            err('%s: 허용되지 않은 endpoint %s' % (name, ds.get('endpoint')))
        if 'errorHandling' not in ds:
            err('%s: data_source %s 에 errorHandling 없음' % (name, ds['id']))
        if 'fallback' not in ds:
            err('%s: data_source %s 에 fallback 없음' % (name, ds['id']))
    walk(layout, where)

# ───── 3. node 로 표현식 문법 확인 ─────
node_src = r'''
const items = JSON.parse(require('fs').readFileSync(0, 'utf8'));
const bad = [];
for (const [where, expr] of items) {
  const src = expr.replace(/\$args/g, '__args').replace(/\$event/g, '__event').replace(/\$computed/g, '__computed')
                  .replace(/\$t\(/g, '__t(').replace(/\$localized\(/g, '__loc(');
  try { new Function('return (' + src + ');'); } catch (e) { bad.push(where + ' :: ' + expr.slice(0, 120) + ' :: ' + e.message); }
}
process.stdout.write(JSON.stringify(bad));
'''
try:
    res = subprocess.run(['node', '-e', node_src], input=json.dumps(expressions), capture_output=True, text=True, timeout=60)
    if res.returncode != 0:
        warnings.append('node 표현식 검사 실행 실패: ' + res.stderr[:200])
    else:
        for b in json.loads(res.stdout or '[]'):
            err('표현식 문법 오류: ' + b)
except FileNotFoundError:
    warnings.append('node 가 없어 표현식 문법 검사를 건너뜀')

# ───── 4. 언어 파일 ─────
lang = {loc: load_json(os.path.join(ROOT, 'resources', 'lang', loc + '.json')) for loc in ('ko', 'en')}
for loc, d in lang.items():
    if MOD in d:
        err('%s.json 최상위에 모듈 접두사(%s) 금지' % (loc, MOD))
ko_keys, en_keys = flatten_keys(lang['ko']), flatten_keys(lang['en'])
for k in sorted(ko_keys ^ en_keys):
    err('ko/en 키 불일치: ' + k)
routes = load_json(os.path.join(ROOT, 'resources', 'routes', 'admin.json'))
def walk_strings(n, where):
    if isinstance(n, dict):
        for v in n.values():
            walk_strings(v, where)
    elif isinstance(n, list):
        for v in n:
            walk_strings(v, where)
    elif isinstance(n, str):
        check_string(n, where)


walk_strings(routes, 'routes/admin.json')
js = open(os.path.join(ROOT, 'resources', 'assets', 'module.js'), encoding='utf-8').read()
for m in re.finditer(r"\bt\('([a-z0-9_.]+)'", js):
    t_keys.add(m.group(1))
for k in sorted(t_keys):
    for loc, keys in (('ko', ko_keys), ('en', en_keys)):
        if k not in keys:
            err('번역 키 없음 (%s.json): %s' % (loc, k))

# ───── 5. 라우트 ─────
for r in routes.get('routes', []):
    if r.get('layout') not in main_layouts:
        err('routes/admin.json: 레이아웃 없음 %s' % r.get('layout'))
    if not r.get('meta', {}).get('title'):
        err('routes/admin.json: meta.title 없음 %s' % r.get('path'))

# ───── 6. dist 와 원본 JS 동일 ─────
dist = open(os.path.join(ROOT, 'dist', 'js', 'module.iife.js'), encoding='utf-8').read()
if dist != js:
    err('dist/js/module.iife.js 가 resources/assets/module.js 와 다릅니다 (node scripts/build.mjs)')

print('JSON files: %d · layouts: %d · expressions: %d · $t keys: %d' % (len(all_json), len(main_layouts), len(expressions), len(t_keys)))
for w in warnings:
    print('WARN  ' + w)
for x in errors:
    print('ERROR ' + x)
print('OK' if not errors else 'FAILED (%d)' % len(errors))
sys.exit(1 if errors else 0)
