"""G7 레이아웃 JSON 생성 도우미 (scripts/gen_layouts.py 에서 사용)

규칙 (사용자 라이브 사이트 경험 반영)
- {{ }} 표현식에 optional chaining(?.) / nullish(??) 를 쓰지 않습니다. && / || 로 대신합니다.
- className 은 sirsoft-admin_basic 빌드 CSS 에 있는 클래스만 씁니다 (scripts/validate.py 가 검사).
"""

MOD = 'custom-sales_stats'
API = '/api/modules/' + MOD


def t(key):
    """번역 키 → "$t:custom-sales_stats.<key>" """
    return '$t:' + MOD + '.' + key


def tq(key):
    """표현식 안에서 쓰는 번역 문자열 리터럴 → "'$t:custom-sales_stats.<key>'" """
    return "'" + t(key) + "'"


def g(path):
    """'a.b.c' → '(a && a.b && a.b.c)' — ?. 대신 쓰는 안전한 접근"""
    parts = path.split('.')
    acc = []
    cur = parts[0]
    acc.append(cur)
    for p in parts[1:]:
        cur = cur + '.' + p
        acc.append(cur)
    if len(acc) == 1:
        return acc[0]
    return '(' + ' && '.join(acc) + ')'


def arr(path):
    return '(' + g(path) + ' || [])'


def obj(path):
    return '(' + g(path) + ' || {})'


def e(expr):
    return '{{' + expr + '}}'


def _node(type_, name, props=None, children=None, text=None, **kw):
    n = {'type': type_, 'name': name}
    if 'id' in kw:
        n = {'id': kw.pop('id'), **n}
    if kw.get('if') is not None:
        n['if'] = kw.pop('if')
    else:
        kw.pop('if', None)
    if kw.get('iteration') is not None:
        n['iteration'] = kw.pop('iteration')
    else:
        kw.pop('iteration', None)
    if props:
        n['props'] = props
    if text is not None:
        n['text'] = text
    if children:
        n['children'] = [c for c in children if c is not None]
    if kw.get('actions'):
        n['actions'] = kw.pop('actions')
    else:
        kw.pop('actions', None)
    n.update({k: v for k, v in kw.items() if v is not None})
    return n


def B(name, props=None, children=None, text=None, **kw):
    return _node('basic', name, props, children, text, **kw)


def C(name, props=None, children=None, text=None, **kw):
    return _node('composite', name, props, children, text, **kw)


def div(cls, children=None, **kw):
    props = kw.pop('props', {}) or {}
    if cls:
        props = {'className': cls, **props}
    return B('Div', props, children, **kw)


def span(cls, text, **kw):
    props = kw.pop('props', {}) or {}
    if cls:
        props = {'className': cls, **props}
    return B('Span', props or None, None, text, **kw)


def icon(name, cls='', **kw):
    p = {'name': name}
    if cls:
        p['className'] = cls
    return B('Icon', p, **kw)


def iterate(source, item, index=None):
    it = {'source': e(source), 'item_var': item}
    if index:
        it['index_var'] = index
    return it


def navigate(path, query, merge=True):
    return {'type': 'click', 'handler': 'navigate', 'params': {'path': path, 'mergeQuery': merge, 'query': query}}


def partial_file(description, node):
    return {'meta': {'is_partial': True, 'description': description}, **node}
