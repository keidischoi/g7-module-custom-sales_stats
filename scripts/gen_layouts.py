#!/usr/bin/env python3
"""custom-sales_stats 관리자 레이아웃 생성기

    python3 scripts/gen_layouts.py

생성 파일 (생성 결과도 저장소에 함께 커밋합니다 — 모듈 설치 시 JSON 만 읽힙니다)
- resources/layouts/admin/admin_sales_stats.json
- resources/layouts/admin/partials/admin_sales_stats/_header|_filters|_tabs|_tab_overview|_tab_ecommerce|_tab_market.json
- resources/layouts/admin/admin_sales_stats_seller.json
- resources/layouts/admin/partials/admin_sales_stats_seller/_header|_filters|_profile|_body.json

표현식 규칙: ?. / ?? 를 쓰지 않습니다 (라이브 7.0.x 에서 목록·입력이 비는 문제가 있었음). scripts/validate.py 로 검사합니다.
"""
import json
import os
import sys

sys.path.insert(0, os.path.dirname(__file__))
from g7 import (API, MOD, B, C, arr, div, e, g, icon, iterate, navigate, obj,  # noqa: E402
                partial_file, span, t, tq)

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
LAYOUT_DIR = os.path.join(ROOT, 'resources', 'layouts', 'admin')
VERSION = '2.0.0'
MAIN_PATH = '/admin/sales-stats'

# ───────────── 스타일 ─────────────
CARD = 'bg-white dark:bg-gray-800 rounded-xl border border-gray-200 dark:border-gray-700 shadow-sm'
H2 = 'text-base font-semibold text-gray-900 dark:text-white'
MUTED = 'text-sm text-gray-500 dark:text-gray-400'
XS_MUTED = 'text-xs text-gray-500 dark:text-gray-400'
BTN = ('inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg border border-gray-300 '
       'dark:border-gray-600 bg-white dark:bg-gray-800 text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700')
BTN_PRIMARY = 'inline-flex items-center gap-2 px-3 py-2 text-sm font-medium rounded-lg bg-blue-600 hover:bg-blue-700 text-white'
BTN_XS = ('inline-flex items-center gap-1 px-2 py-1 text-xs rounded-md border border-gray-300 dark:border-gray-600 '
          'text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-gray-700 whitespace-nowrap')
CHIP_ON = 'px-3 py-1 text-sm rounded-full border border-blue-600 bg-blue-600 text-white'
CHIP_OFF = ('px-3 py-1 text-sm rounded-full border border-gray-300 dark:border-gray-600 bg-white dark:bg-gray-800 '
            'text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700')
TH = 'px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap'
TH_R = 'px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 whitespace-nowrap'
TD = 'px-3 py-2 text-gray-700 dark:text-gray-200'
TD_R = 'px-3 py-2 text-right text-gray-700 dark:text-gray-200 whitespace-nowrap'
LINK = 'text-left font-medium text-blue-600 dark:text-blue-400 hover:underline'
INPUT = 'input'

TAB = "(query.tab || 'overview')"
F = obj('meta.data.filters')
CAN_EXPORT = '!!' + g('meta.data.can.export')

# 기간·기준 공통 파라미터 (값이 비면 코어가 요청에서 뺍니다)
BASE_PARAMS = {k: e("query.%s || ''" % k) for k in ('from', 'to', 'granularity', 'basis', 'compare')}
MARKET_PARAMS = {k: e("query.%s || ''" % k) for k in ('type', 'payment_mode', 'category')}


def period_query():
    """판매자 상세 등으로 이동할 때 유지할 기간 조건"""
    return {k: e("query.%s || ''" % k) for k in ('from', 'to', 'granularity', 'basis', 'compare')}


def export_qs():
    return ("'from=' + (" + F + ".from || '') + '&to=' + (" + F + ".to || '') + '&granularity=' + (" + F +
            ".granularity || '') + '&basis=' + (" + F + ".basis || 'paid') + '&compare=' + (" + F +
            ".compare ? '1' : '0') + '&type=' + (query.type || '') + '&payment_mode=' + (query.payment_mode || '') + "
            "'&category=' + (query.category || '') + '&q=' + (query.q || '')")


def csv_button(scope, dataset, extra="''", label='common.csv'):
    url = "'" + API + "/export?scope=" + scope + "&dataset=" + dataset + "&' + " + export_qs() + " + " + extra
    fname = "'sales_stats_" + scope + "_" + dataset + "_' + (" + F + ".from || '') + '_' + (" + F + ".to || '') + '.csv'"
    return B('Button', {'type': 'button', 'className': BTN_XS, 'title': t('common.csv_hint')},
             [icon('download'), span(None, t(label))],
             **{'if': e(CAN_EXPORT),
                'actions': [{'type': 'click', 'handler': 'downloadAttachment', 'params': {'url': e(url), 'filename': e(fname)}}]})


def section(sid, title_key, body, right=None, desc_key=None, cond=None, cls=''):
    head_left = [B('H2', {'className': H2}, text=t(title_key))]
    if desc_key:
        head_left.append(B('P', {'className': XS_MUTED + ' mt-1'}, text=t(desc_key)))
    head = div('flex items-start justify-between gap-3 flex-wrap mb-4',
               [div('min-w-0', head_left), div('flex items-center gap-2 flex-wrap', right) if right else None])
    return div((CARD + ' p-5 ' + cls).strip(), [head] + (body if isinstance(body, list) else [body]),
               id=sid, **{'if': cond})


def empty(desc_key='common.empty', icon_name='chart-line', cond=None):
    return C('EmptyState', {'title': t('common.empty_title'), 'description': t(desc_key), 'iconName': icon_name},
             **{'if': cond})


# ───────────── KPI 카드 ─────────────

def kpi_card(kexpr, label_key, icon_name, cond=None, hint_key=None):
    K = '(' + kexpr + ' || {})'
    has_change = K + '.change !== null && ' + K + '.change !== undefined'
    change_cls = ("{{" + K + ".trend === 'up' ? 'font-semibold text-green-600 dark:text-green-400' : (" + K +
                  ".trend === 'down' ? 'font-semibold text-red-600 dark:text-red-400' : 'font-semibold text-gray-500 dark:text-gray-400')}}")
    change_txt = e("(" + K + ".change > 0 ? '▲ ' : (" + K + ".change < 0 ? '▼ ' : '')) + Math.abs(" + K + ".change) + '%'")
    children = [
        div('flex items-center justify-between gap-2', [
            span('text-sm text-gray-500 dark:text-gray-400 truncate', t(label_key)),
            icon(icon_name, 'text-gray-400 dark:text-gray-500'),
        ]),
        div('mt-2 text-2xl font-bold text-gray-900 dark:text-white truncate', text=e(K + ".formatted || '-'")),
        div('mt-2 flex items-center gap-2 flex-wrap text-xs', [
            span(change_cls, change_txt, **{'if': e(has_change)}),
            span('text-gray-400 dark:text-gray-500', t('kpi.no_change'),
                 **{'if': e('!(' + has_change + ') && ' + F + '.compare')}),
            span('text-gray-500 dark:text-gray-400', t('kpi.previous'), **{'if': e('!!' + K + '.previous_formatted')}),
            span('text-gray-600 dark:text-gray-300', e(K + '.previous_formatted'), **{'if': e('!!' + K + '.previous_formatted')}),
        ]),
    ]
    if hint_key:
        children.append(B('P', {'className': 'mt-1 text-xs text-gray-400 dark:text-gray-500'}, text=t(hint_key)))
    return div(CARD + ' p-4 min-w-0', children, **{'if': cond})


def kpi_grid(base, items, cols='grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4'):
    return div(cols, [kpi_card(g(base + '.' + key), label, ic) for key, label, ic in items])


# ───────────── 표 ─────────────

def table(rows_expr, columns, item='row', index='idx', empty_key='common.empty', row_id=None):
    """columns: [(header_key|None, cell_children(list)|text, align)]"""
    head = B('Thead', {'className': 'bg-gray-50 dark:bg-gray-700'}, [
        B('Tr', None, [B('Th', {'className': TH_R if al == 'right' else TH}, text=t(h) if h else '') for h, _, al in columns])
    ])
    cells = []
    for _, cell, al in columns:
        cls = TD_R if al == 'right' else TD
        if isinstance(cell, str):
            cells.append(B('Td', {'className': cls}, text=cell))
        else:
            cells.append(B('Td', {'className': cls}, cell))
    body = B('Tbody', {'className': 'divide-y divide-gray-200 dark:divide-gray-700'}, [
        B('Tr', {'className': 'hover:bg-gray-50 dark:hover:bg-gray-700'}, cells, iteration=iterate(rows_expr, item, index),
          **({'id': row_id} if row_id else {}))
    ])
    return div(None, [
        div('overflow-x-auto', [B('Table', {'className': 'min-w-full text-sm'}, [head, body])],
            **{'if': e(rows_expr + '.length > 0')}),
        empty(empty_key, cond=e(rows_expr + '.length === 0')),
    ])


def rank_badge(item='row'):
    return span('inline-flex items-center justify-center w-6 h-6 rounded-full bg-gray-100 dark:bg-gray-700 text-xs font-semibold text-gray-700 dark:text-gray-200',
                e(item + '.rank'))


def share_bar(item='row'):
    return div('flex items-center gap-2', [
        div('w-24 h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden', [
            div('h-2 rounded-full', props={'style': {'width': e('(' + item + ".bar || 0) + '%'"),
                                                     'backgroundColor': e(item + ".color || '#6366F1'")}}),
        ]),
        span('text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap', e('(' + item + ".share || 0) + '%'")),
    ])


def member_cell(m, with_buttons=True):
    """회원 셀 — data-g7-user 로 쪽지 모듈 회원 메뉴 연동, [회원정보][쪽지] 버튼"""
    M = '(' + m + ' || {})'
    has_uuid = '!!' + M + '.uuid'
    name = span('font-medium text-gray-900 dark:text-white truncate', e(M + ".nickname || '-'"),
                props={'data-g7-user': e(M + ".uuid || ''"), 'data-g7-user-name': e(M + ".nickname || ''"),
                       'data-g7-user-click': '1'})
    children = [
        div('min-w-0', [
            name,
            div('text-xs text-gray-400 dark:text-gray-500 truncate', text=e(M + '.email'), **{'if': e('!!' + M + '.email')}),
            div('text-xs text-gray-400 dark:text-gray-500', text=t('member.withdrawn'),
                **{'if': e('!!' + m + ' && !' + M + '.uuid')}),
        ]),
    ]
    if with_buttons:
        children.append(div('flex items-center gap-1 shrink-0', [
            B('Button', {'type': 'button', 'className': BTN_XS, 'title': t('member.open')}, [icon('user'), span(None, t('member.info'))],
              actions=[{'type': 'click', 'handler': MOD + '.openMember', 'params': {'uuid': e(M + '.uuid')}}]),
            B('Button', {'type': 'button', 'className': BTN_XS, 'title': t('member.note_hint')}, [icon('envelope'), span(None, t('member.note'))],
              actions=[{'type': 'click', 'handler': MOD + '.composeNote',
                        'params': {'uuid': e(M + '.uuid'), 'name': e(M + ".nickname || ''")}}]),
        ], **{'if': e(has_uuid)}))
    return [div('flex items-center justify-between gap-2 min-w-0', children)]


def nav_button(path_expr, label_key, icon_name='arrow-right', query=None, merge=False):
    return B('Button', {'type': 'button', 'className': BTN_XS}, [icon(icon_name), span(None, t(label_key))],
             actions=[navigate(path_expr, query or {}, merge)])


def link_text(text_expr, path_expr, cond=None):
    return B('Button', {'type': 'button', 'className': LINK}, text=text_expr,
             actions=[navigate(path_expr, {}, False)], **{'if': cond})


# ───────────── 차트 ─────────────

def bar_chart(labels_expr, datasets_expr, height=280):
    return C('BarChart', {'labels': e(labels_expr), 'datasets': e(datasets_expr), 'height': height,
                          'showLegend': True, 'showYGrid': True, 'showYAxis': True, 'className': 'w-full'})


def donut_card(sid, title_key, data_expr, value_field="amount_formatted", cond=None, empty_icon='chart-pie'):
    legend = div('flex items-center justify-between gap-2 text-sm', [
        div('flex items-center gap-2 min-w-0', [
            div('w-2 h-2 rounded-full shrink-0', props={'style': {'backgroundColor': e("d.color || '#6366F1'")}}),
            span('truncate text-gray-700 dark:text-gray-200', e("d.label || d.name || '-'")),
        ]),
        div('flex items-center gap-2 shrink-0', [
            span('text-xs text-gray-500 dark:text-gray-400', e("(d.share || 0) + '%'")),
            span('text-gray-900 dark:text-white tabular-nums', e("d." + value_field + " || d.value")),
        ]),
    ], iteration=iterate(data_expr, 'd', 'di'))
    body = [
        div('flex flex-col items-center gap-4', [
            C('DonutChart', {'data': e(data_expr + ".map(d => ({ name: d.label || d.name || '-', value: Number(d.value || 0), color: d.color }))"),
                             'size': 180, 'cutout': '65%', 'showLegend': False}),
            div('w-full flex flex-col gap-2', [legend]),
        ], **{'if': e(data_expr + '.length > 0')}),
        empty('common.empty', empty_icon, cond=e(data_expr + '.length === 0')),
    ]
    return section(sid, title_key, body, cond=cond)


# ───────────── 공통: 헤더 · 필터 ─────────────

def period_line():
    gran = ("{{" + F + ".granularity === 'week' ? " + tq('granularity.week') + " : (" + F + ".granularity === 'month' ? " +
            tq('granularity.month') + " : (" + F + ".granularity === 'year' ? " + tq('granularity.year') + " : " + tq('granularity.day') + "))}}")
    return div('flex items-center gap-2 flex-wrap mt-1 ' + MUTED, [
        icon('calendar'),
        span(None, e("(" + F + ".from || '') + ' ~ ' + (" + F + ".to || '')")),
        span('text-gray-300 dark:text-gray-600', '·'),
        span(None, gran),
        span('text-gray-300 dark:text-gray-600', '·'),
        span(None, e(F + ".timezone || 'Asia/Seoul'")),
        span('text-gray-300 dark:text-gray-600', '·', **{'if': e('!!' + F + '.previous')}),
        span(None, t('filters.compare_with'), **{'if': e('!!' + F + '.previous')}),
        span(None, e("(" + F + ".previous && " + F + ".previous.from) + ' ~ ' + (" + F + ".previous && " + F + ".previous.to)"),
             **{'if': e('!!' + F + '.previous')}),
    ])


def refresh_button(ids, cond=None):
    return B('Button', {'type': 'button', 'className': BTN, 'title': t('common.refresh')},
             [icon('rotate'), span(None, t('common.refresh'))],
             actions=[{'type': 'click', 'handler': 'sequence',
                       'actions': [{'handler': 'refetchDataSource', 'params': {'dataSourceId': i}} for i in ids]}],
             **{'if': cond})


def filters_card(path, market_filters_cond):
    cur_preset = F + '.preset'
    preset_chip = B('Button', {'type': 'button',
                               'className': "{{p.key === " + cur_preset + " ? '" + CHIP_ON + "' : '" + CHIP_OFF + "'}}"},
                    text=e('p.label'), iteration=iterate(arr('meta.data.presets'), 'p', 'pi'),
                    actions=[navigate(path, {'from': e('p.from'), 'to': e('p.to'), 'granularity': e('p.granularity'), 'page': ''})])
    gran_btns = []
    for k in ('day', 'week', 'month', 'year'):
        gran_btns.append(B('Button', {'type': 'button',
                                      'className': "{{(" + F + ".granularity || 'day') === '" + k + "' ? '" + CHIP_ON + "' : '" + CHIP_OFF + "'}}"},
                           text=t('granularity.' + k), actions=[navigate(path, {'granularity': k, 'page': ''})]))

    def date_input(key):
        return B('Input', {'type': 'date', 'className': INPUT + ' w-40', 'value': e("query." + key + " || " + F + "." + key + " || ''")},
                 actions=[{'type': 'change', 'handler': 'navigate',
                           'params': {'path': path, 'mergeQuery': True, 'query': {key: e('$event.target.value'), 'page': ''}}}])

    def select(key, options, width='w-40', default="''"):
        return B('Select', {'className': width, 'value': e('query.' + key + ' || ' + default), 'options': options},
                 actions=[{'type': 'change', 'handler': 'navigate',
                           'params': {'path': path, 'mergeQuery': True, 'query': {key: e('$event.target.value'), 'page': ''}}}])

    compare_btn = B('Button', {'type': 'button',
                               'className': "{{" + F + ".compare ? '" + CHIP_ON + "' : '" + CHIP_OFF + "'}}"},
                    [icon('code-compare'), span(None, t('filters.compare'))],
                    actions=[navigate(path, {'compare': e(F + ".compare ? '0' : '1'")})])
    label = lambda k: span(XS_MUTED + ' font-medium', t(k))  # noqa: E731
    market_row = div('flex items-center gap-3 flex-wrap pt-3 border-t border-gray-200 dark:border-gray-700', [
        label('filters.market'),
        select('type', [{'value': '', 'label': t('filters.type_all')}, {'value': 'physical', 'label': t('filters.type_physical')},
                        {'value': 'digital', 'label': t('filters.type_digital')}]),
        select('payment_mode', [{'value': '', 'label': t('filters.payment_mode_all')}, {'value': 'escrow', 'label': t('filters.payment_mode_escrow')},
                                {'value': 'direct', 'label': t('filters.payment_mode_direct')}]),
        B('Select', {'className': 'w-48', 'value': e("query.category || ''"),
                     'options': e("[{ value: '', label: " + tq('filters.category_all') + " }].concat(" + arr('meta.data.market_categories') + ")")},
          actions=[{'type': 'change', 'handler': 'navigate',
                    'params': {'path': path, 'mergeQuery': True, 'query': {'category': e('$event.target.value'), 'page': ''}}}]),
        B('Button', {'type': 'button', 'className': BTN_XS}, [icon('xmark'), span(None, t('filters.reset_market'))],
          actions=[navigate(path, {'type': '', 'payment_mode': '', 'category': '', 'page': ''})],
          **{'if': e('!!(query.type || query.payment_mode || query.category)')}),
    ], **{'if': market_filters_cond})
    return div(CARD + ' p-4 flex flex-col gap-3', [
        div('flex items-center gap-2 flex-wrap', [label('filters.period'), preset_chip]),
        div('flex items-center gap-3 flex-wrap', [
            div('flex items-center gap-2', [date_input('from'), span('text-gray-400', '~'), date_input('to')]),
            div('flex items-center gap-1 flex-wrap', gran_btns),
            select('basis', [{'value': 'paid', 'label': t('filters.basis_paid')}, {'value': 'confirmed', 'label': t('filters.basis_confirmed')}],
                   width='w-48', default="'paid'"),
            compare_btn,
        ]),
        B('P', {'className': 'text-xs text-amber-600 dark:text-amber-400'}, text=t('filters.granularity_adjusted'),
          **{'if': e('!!' + F + '.requested_granularity && ' + F + '.requested_granularity !== ' + F + '.granularity')}),
        B('P', {'className': 'text-xs text-gray-400 dark:text-gray-500'}, text=t('filters.basis_help')),
        market_row,
    ])


# ───────────── 메인 레이아웃 ─────────────

def ds(id_, endpoint, params, cond=None, fallback=None, strategy=None, errors=None):
    d = {'id': id_, 'type': 'api', 'endpoint': endpoint, 'method': 'GET', 'auto_fetch': True, 'auth_required': True}
    if cond:
        d['if'] = cond
    if strategy:
        d['loading_strategy'] = strategy
    d['params'] = params
    d['fallback'] = fallback if fallback is not None else {'data': {}}
    d['errorHandling'] = errors or {
        '403': {'handler': 'toast', 'params': {'type': 'error', 'message': t('errors.forbidden')}},
        '404': {'handler': 'toast', 'params': {'type': 'warning', 'message': t('errors.unavailable')}},
        '500': {'handler': 'toast', 'params': {'type': 'error', 'message': t('errors.server')}},
        'default': {'handler': 'toast', 'params': {'type': 'error', 'message': t('errors.load_failed')}},
    }
    return d


def meta_ds():
    return ds('meta', API + '/meta', dict(BASE_PARAMS),
              fallback={'data': {'tabs': [{'id': 'overview', 'label': t('tabs.overview')}], 'presets': [], 'filters': {},
                                 'can': {}, 'available': {}, 'market_categories': []}})


def main_data_sources():
    on = lambda tab: e(TAB + " === '" + tab + "'")  # noqa: E731
    p_all = dict(BASE_PARAMS)
    p_m = {**BASE_PARAMS, **MARKET_PARAMS}
    lim = {'limit': '10'}
    return [
        meta_ds(),
        ds('overview', API + '/overview', p_all, on('overview')),
        ds('ecoSummary', API + '/ecommerce/summary', p_all, on('ecommerce')),
        ds('ecoSeries', API + '/ecommerce/timeseries', p_all, on('ecommerce')),
        ds('ecoProducts', API + '/ecommerce/products', {**p_all, **lim}, on('ecommerce'), {'data': {'rows': []}}),
        ds('ecoCategories', API + '/ecommerce/categories', {**p_all, **lim}, on('ecommerce'), {'data': {'rows': []}}),
        ds('ecoBuyers', API + '/ecommerce/buyers', {**p_all, **lim}, on('ecommerce'), {'data': {'rows': []}}),
        ds('ecoBreakdowns', API + '/ecommerce/breakdowns', p_all, on('ecommerce')),
        ds('mkSummary', API + '/market/summary', p_m, on('market')),
        ds('mkSeries', API + '/market/timeseries', p_m, on('market')),
        ds('mkSellers', API + '/market/sellers',
           {**p_m, 'q': e("query.q || ''"), 'sort': e("query.sort || ''"), 'dir': e("query.dir || ''"),
            'page': e("query.page || ''"), 'per_page': '20'}, on('market'), {'data': {'rows': [], 'meta': {'last_page': 1}}}),
        ds('mkListings', API + '/market/listings', {**p_m, **lim}, on('market'), {'data': {'rows': []}}),
        ds('mkBuyers', API + '/market/buyers', {**p_m, **lim}, on('market'), {'data': {'rows': []}}),
        ds('mkBreakdowns', API + '/market/breakdowns', p_m, on('market')),
        ds('mkSettlements', API + '/market/settlements', {**p_m, 'limit': '10'}, on('market'), {'data': {'rows': [], 'snapshot': {}}}),
    ]


def main_header():
    right = [
        refresh_button(['meta', 'overview'], e(TAB + " === 'overview'")),
        refresh_button(['meta', 'ecoSummary', 'ecoSeries', 'ecoProducts', 'ecoCategories', 'ecoBuyers', 'ecoBreakdowns'],
                       e(TAB + " === 'ecommerce'")),
        refresh_button(['meta', 'mkSummary', 'mkSeries', 'mkSellers', 'mkListings', 'mkBuyers', 'mkBreakdowns', 'mkSettlements'],
                       e(TAB + " === 'market'")),
        div('flex items-center', [csv_button('ecommerce', 'period', label='common.csv_period')], **{'if': e(TAB + " === 'ecommerce'")}),
        div('flex items-center', [csv_button('market', 'period', label='common.csv_period')], **{'if': e(TAB + " === 'market'")}),
    ]
    return partial_file('판매 통계 — 제목 · 기간 · 새로고침 · CSV', div('flex items-start justify-between gap-4 flex-wrap', [
        div('min-w-0', [
            div('flex items-center gap-2', [icon('chart-line', 'text-blue-600 dark:text-blue-400'),
                                            B('H1', {'className': 'text-2xl font-bold text-gray-900 dark:text-white'}, text=t('title'))]),
            period_line(),
        ]),
        div('flex items-center gap-2 flex-wrap', right),
    ], id='sales_stats_header'))


def main_tabs():
    return partial_file('판매 통계 — 탭 (서버 meta.tabs 기준, 비활성 모듈 탭 숨김)', C('TabNavigation', {
        'tabs': e(arr('meta.data.tabs')),
        'activeTabId': e(TAB),
        'variant': 'underline',
    }, id='sales_stats_tabs', actions=[{'event': 'onTabChange', 'type': 'change', 'handler': 'navigate',
                                        'params': {'path': MAIN_PATH, 'mergeQuery': True,
                                                   'query': {'tab': e('$args[0]'), 'page': '', 'q': '', 'sort': '', 'dir': ''}}}]))


# ── 전체 탭 ──

def tab_overview():
    O = 'overview.data'
    avail_e = '!!' + g(O + '.available.ecommerce')
    avail_m = '!!' + g(O + '.available.market')
    series = obj(O + '.series')
    datasets = ("[{ label: " + tq('chart.ecommerce_sales') + ", data: " + series + ".ecommerce || [], backgroundColor: '#6366F1', borderRadius: 4, show: " + avail_e +
                " }, { label: " + tq('chart.market_gmv') + ", data: " + series + ".market || [], backgroundColor: '#10B981', borderRadius: 4, show: " + avail_m +
                " }].filter(d => d.show)")
    snap = O + '.market.snapshot'
    snap_items = [
        ('settlement_pending_amount_formatted', 'snapshot.settlement_pending', 'hourglass-half', '/admin/user-markets/orders', {'settlement_status': 'pending'}),
        ('listings_pending_review', 'snapshot.listings_pending_review', 'clipboard-check', None, None),
        ('sellers_pending', 'snapshot.sellers_pending', 'user-clock', None, None),
        ('open_orders', 'snapshot.open_orders', 'box-open', '/admin/user-markets/orders', {}),
    ]
    snap_cards = []
    for key, label, ic, path, q in snap_items:
        children = [div('flex items-center gap-2 ' + MUTED, [icon(ic), span(None, t(label))]),
                    div('mt-1 text-xl font-semibold text-gray-900 dark:text-white', text=e(obj(snap) + '.' + key + ' || 0'))]
        if path:
            children.append(div('mt-2', [nav_button(path, 'common.go_manage', 'arrow-right', q, False)]))
        snap_cards.append(div(CARD + ' p-4', children))

    top_products = table(arr(O + '.top_products'), [
        (None, [rank_badge()], 'left'),
        ('col.product', [link_text(e('row.name'), e('row.admin_url'))], 'left'),
        ('col.quantity', e('row.quantity'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.share', [share_bar()], 'left'),
    ])
    top_sellers = table(arr(O + '.top_sellers'), [
        (None, [rank_badge()], 'left'),
        ('col.shop', [link_text(e('row.shop_name'), e('row.detail_url'))], 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.share', [share_bar()], 'left'),
    ])
    return partial_file('판매 통계 — 전체 탭', div('flex flex-col gap-6', [
        div('rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 p-4 text-sm text-amber-700 dark:text-amber-300',
            text=t('overview.not_combinable'), **{'if': e(g(O) + ' && ' + O + '.combinable === false')}),
        div('grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4', [
            kpi_card(g(O + '.combined.total'), 'overview.total_sales', 'coins', cond=e('!!' + g(O + '.combined'))),
            kpi_card(g(O + '.combined.orders'), 'overview.total_orders', 'receipt', cond=e('!!' + g(O + '.combined'))),
            kpi_card(g(O + '.ecommerce.kpis.sales'), 'overview.ecommerce_sales', 'cart-shopping', cond=e(avail_e)),
            kpi_card(g(O + '.market.kpis.gmv'), 'overview.market_gmv', 'store', cond=e(avail_m)),
            kpi_card(g(O + '.ecommerce.kpis.orders'), 'overview.ecommerce_orders', 'box', cond=e(avail_e)),
            kpi_card(g(O + '.market.kpis.orders'), 'overview.market_orders', 'handshake', cond=e(avail_m)),
            kpi_card(g(O + '.market.kpis.commission'), 'kpi.commission', 'percent', cond=e(avail_m)),
            kpi_card(g(O + '.ecommerce.kpis.cancel_rate'), 'overview.ecommerce_cancel_rate', 'ban', cond=e(avail_e)),
        ]),
        div('grid grid-cols-1 xl:grid-cols-3 gap-6', [
            section('overview_trend', 'overview.trend', [
                bar_chart(series + '.labels || []', datasets),
            ], cls='xl:col-span-2'),
            donut_card('overview_share', 'overview.share', arr(O + '.combined.share'), 'formatted', cond=e('!!' + g(O + '.combined'))),
        ]),
        div('grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4', snap_cards, **{'if': e(avail_m + ' && !!' + g(snap))}),
        div('grid grid-cols-1 xl:grid-cols-2 gap-6', [
            section('overview_top_products', 'overview.top_products', top_products,
                    right=[nav_button(MAIN_PATH, 'common.more', 'arrow-right', {'tab': 'ecommerce'}, True)], cond=e(avail_e)),
            section('overview_top_sellers', 'overview.top_sellers', top_sellers,
                    right=[nav_button(MAIN_PATH, 'common.more', 'arrow-right', {'tab': 'market'}, True)], cond=e(avail_m)),
        ]),
    ], id='sales_stats_tab_overview'))


# ── 이커머스 탭 ──

ECO_KPIS = [
    ('sales', 'kpi.sales', 'coins'), ('orders', 'kpi.orders', 'receipt'), ('aov', 'kpi.aov', 'scale-balanced'),
    ('items', 'kpi.items', 'boxes-stacked'), ('buyers', 'kpi.buyers', 'users'), ('first_order_rate', 'kpi.first_order_rate', 'user-plus'),
    ('discount', 'kpi.discount', 'tags'), ('points_used', 'kpi.points_used', 'coins'), ('shipping', 'kpi.shipping', 'truck'),
    ('cancelled_amount', 'kpi.cancelled_amount', 'rotate-left'), ('cancelled_orders', 'kpi.cancelled_orders', 'ban'),
    ('cancel_rate', 'kpi.cancel_rate', 'percent'),
]


def tab_ecommerce():
    S = obj('ecoSeries.data')
    datasets = ("[{ label: " + tq('chart.sales') + ", data: " + S + ".sales || [], backgroundColor: '#6366F1', borderRadius: 4 }, "
                "{ label: " + tq('chart.orders') + ", data: " + S + ".orders || [], backgroundColor: '#C7D2FE', borderRadius: 4, yAxisID: 'y1' }]")
    products = table(arr('ecoProducts.data.rows'), [
        (None, [rank_badge()], 'left'),
        ('col.product', [div('min-w-0', [link_text(e('row.name'), e('row.admin_url')),
                                         div('text-xs text-gray-400 dark:text-gray-500', text=e('row.product_code'), **{'if': e('!!row.product_code')})])], 'left'),
        ('col.quantity', e('row.quantity'), 'right'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.share', [share_bar()], 'left'),
    ])
    categories = table(arr('ecoCategories.data.rows'), [
        (None, [rank_badge()], 'left'),
        ('col.category', e('row.name'), 'left'),
        ('col.quantity', e('row.quantity'), 'right'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.share', [share_bar()], 'left'),
    ])
    buyers = table(arr('ecoBuyers.data.rows'), [
        (None, [rank_badge()], 'left'),
        ('col.member', member_cell('row.member'), 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.aov', e('row.aov_formatted'), 'right'),
        ('col.last_at', e("row.last_at || '-'"), 'right'),
    ])
    top_orders = table(arr('ecoBreakdowns.data.top_orders'), [
        ('col.order_no', [link_text(e('row.order_number'), e('row.admin_url'))], 'left'),
        ('col.member', member_cell('row.member'), 'left'),
        ('col.status', [span('inline-flex px-2 py-1 rounded-md text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200', e('row.status_label'))], 'left'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.at', e("row.at || '-'"), 'right'),
    ])
    period_rows = table(arr('ecoSeries.data.rows'), [
        ('col.period', e('row.period'), 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.quantity', e('row.items'), 'right'),
        ('col.amount', e('row.sales_formatted'), 'right'),
        ('col.aov', e('row.aov_formatted'), 'right'),
    ])
    guest = obj('ecoBuyers.data.guest')
    return partial_file('판매 통계 — 이커머스 탭', div('flex flex-col gap-6', [
        kpi_grid('ecoSummary.data.kpis', ECO_KPIS),
        section('eco_trend', 'ecommerce.trend', [bar_chart(S + '.labels || []', datasets)], desc_key='ecommerce.trend_desc'),
        div('grid grid-cols-1 xl:grid-cols-3 gap-6', [
            section('eco_products', 'ecommerce.products', products, right=[csv_button('ecommerce', 'products')], cls='xl:col-span-2'),
            donut_card('eco_categories_donut', 'ecommerce.categories_share', arr('ecoCategories.data.rows')),
        ]),
        section('eco_categories', 'ecommerce.categories', categories, right=[csv_button('ecommerce', 'categories')],
                desc_key='ecommerce.categories_desc'),
        section('eco_buyers', 'ecommerce.buyers', [
            buyers,
            div('mt-3 ' + XS_MUTED + ' flex items-center gap-2', [
                span(None, t('ecommerce.guest_orders')), span('font-medium', e(guest + '.orders || 0')),
                span(None, '·'), span('font-medium', e(guest + ".amount_formatted || '-'")),
            ]),
        ], right=[csv_button('ecommerce', 'buyers')]),
        div('grid grid-cols-1 lg:grid-cols-3 gap-6', [
            donut_card('eco_statuses', 'ecommerce.statuses', arr('ecoBreakdowns.data.statuses'), 'value'),
            donut_card('eco_payments', 'ecommerce.payment_methods', arr('ecoBreakdowns.data.payment_methods')),
            donut_card('eco_devices', 'ecommerce.devices', arr('ecoBreakdowns.data.devices'), 'value'),
        ]),
        section('eco_top_orders', 'ecommerce.top_orders', top_orders),
        section('eco_period', 'ecommerce.period_table', period_rows, right=[csv_button('ecommerce', 'period')]),
    ], id='sales_stats_tab_ecommerce'))


# ── 개인마켓 탭 ──

MK_KPIS = [
    ('gmv', 'kpi.gmv', 'coins'), ('orders', 'kpi.orders', 'receipt'), ('aov', 'kpi.aov', 'scale-balanced'),
    ('quantity', 'kpi.items', 'boxes-stacked'), ('buyers', 'kpi.buyers', 'users'), ('active_sellers', 'kpi.active_sellers', 'store'),
    ('commission', 'kpi.commission', 'percent'), ('payout', 'kpi.payout', 'money-bill-transfer'),
    ('settled_amount', 'kpi.settled_amount', 'circle-check'), ('mileage_used', 'kpi.mileage_used', 'coins'),
    ('cancelled_orders', 'kpi.cancelled_orders', 'ban'), ('cancelled_amount', 'kpi.cancelled_amount', 'rotate-left'),
    ('cancel_rate', 'kpi.cancel_rate', 'percent'), ('new_sellers', 'kpi.new_sellers', 'user-plus'),
    ('new_listings', 'kpi.new_listings', 'square-plus'),
]

SELLER_KPIS = [k for k in MK_KPIS if k[0] not in ('active_sellers', 'new_sellers')]


def market_snapshot(snap_path, seller=False):
    S = obj(snap_path)
    items = [
        ('settlement_pending_amount_formatted', 'snapshot.settlement_pending', 'hourglass-half'),
        ('settlement_pending_orders', 'snapshot.settlement_pending_orders', 'list-check'),
        ('listings_on_sale', 'snapshot.listings_on_sale', 'tag'),
        ('listings_pending_review', 'snapshot.listings_pending_review', 'clipboard-check'),
    ]
    if not seller:
        items += [('sellers_approved', 'snapshot.sellers_approved', 'store'), ('sellers_pending', 'snapshot.sellers_pending', 'user-clock'),
                  ('sellers_suspended', 'snapshot.sellers_suspended', 'user-slash'), ('open_orders', 'snapshot.open_orders', 'box-open')]
    cells = [div('rounded-lg bg-gray-50 dark:bg-gray-700 p-3', [
        div('flex items-center gap-2 ' + XS_MUTED, [icon(ic), span(None, t(label))]),
        div('mt-1 text-lg font-semibold text-gray-900 dark:text-white', text=e(S + '.' + key + ' || 0')),
    ]) for key, label, ic in items]
    return section('mk_snapshot' + ('_seller' if seller else ''), 'snapshot.title', [
        div('grid grid-cols-2 lg:grid-cols-4 gap-3', cells),
    ], desc_key='snapshot.desc', right=None if seller else [
        nav_button('/admin/user-markets/orders', 'snapshot.go_settlements', 'arrow-right', {'settlement_status': 'pending'}, False)])


def seller_detail_button(item='row'):
    return B('Button', {'type': 'button', 'className': BTN_XS}, [icon('chart-column'), span(None, t('seller.detail'))],
             actions=[navigate(e(item + '.detail_url'), period_query(), False)])


def sellers_section():
    sort_opts = [{'value': k, 'label': t('sort.' + k)} for k in
                 ('amount', 'orders', 'quantity', 'buyers', 'aov', 'commission', 'settlement_pending', 'rating', 'cancel_rate', 'recent')]
    toolbar = [
        B('Input', {'type': 'search', 'className': INPUT + ' w-48', 'placeholder': t('sellers.search_placeholder'),
                    'defaultValue': e("query.q || ''")},
          actions=[{'type': 'keypress', 'key': 'Enter', 'handler': 'navigate',
                    'params': {'path': MAIN_PATH, 'mergeQuery': True, 'query': {'q': e('$event.target.value'), 'page': ''}}}]),
        B('Select', {'className': 'w-40', 'value': e("query.sort || 'amount'"), 'options': sort_opts},
          actions=[{'type': 'change', 'handler': 'navigate',
                    'params': {'path': MAIN_PATH, 'mergeQuery': True, 'query': {'sort': e('$event.target.value'), 'page': ''}}}]),
        B('Button', {'type': 'button', 'className': BTN_XS, 'title': t('sort.toggle_dir')},
          [icon(e("query.dir === 'asc' ? 'arrow-up-short-wide' : 'arrow-down-wide-short'")),
           span(None, e("query.dir === 'asc' ? " + tq('sort.asc') + " : " + tq('sort.desc')))],
          actions=[navigate(MAIN_PATH, {'dir': e("query.dir === 'asc' ? 'desc' : 'asc'"), 'page': ''})]),
        B('Button', {'type': 'button', 'className': BTN_XS}, [icon('xmark'), span(None, t('sellers.clear_search'))],
          actions=[navigate(MAIN_PATH, {'q': '', 'page': ''})], **{'if': e('!!query.q')}),
        csv_button('market', 'sellers'),
    ]
    shop_cell = [div('flex items-center gap-2 min-w-0', [
        B('Img', {'src': e('row.logo_url'), 'alt': '', 'className': 'w-8 h-8 rounded-full object-cover shrink-0'}, **{'if': e('!!row.logo_url')}),
        div('w-8 h-8 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0',
            [icon('store', 'text-gray-400')], **{'if': e('!row.logo_url')}),
        div('min-w-0', [
            link_text(e('row.shop_name'), e('row.detail_url')),
            div('flex items-center gap-1 flex-wrap', [
                span('text-xs text-gray-500 dark:text-gray-400', e('row.seller_status_label')),
                span('inline-flex px-1 rounded text-xs bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
                     t('seller.designated'), **{'if': e('!!row.is_designated')}),
            ]),
        ]),
    ])]
    rows = table(arr('mkSellers.data.rows'), [
        (None, [rank_badge()], 'left'),
        ('col.shop', shop_cell, 'left'),
        ('col.member', member_cell('row.member'), 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.buyers', e('row.buyers'), 'right'),
        ('col.amount', [div(None, text=e('row.amount_formatted')), div('flex justify-end mt-1', [share_bar()])], 'right'),
        ('col.aov', e('row.aov_formatted'), 'right'),
        ('col.commission', e('row.commission_formatted'), 'right'),
        ('col.settlement_pending', e('row.pending_amount_formatted'), 'right'),
        ('col.rating', e("row.review_count > 0 ? ('★ ' + row.rating + ' (' + row.review_count + ')') : '-'"), 'right'),
        ('col.cancel_rate', e("(row.cancel_rate || 0) + '%'"), 'right'),
        (None, [div('flex items-center gap-1 justify-end', [
            seller_detail_button(),
            B('A', {'href': e('row.shop_url'), 'target': '_blank', 'className': BTN_XS}, [icon('arrow-up-right-from-square'), span(None, t('seller.shop'))]),
        ])], 'right'),
    ], empty_key='sellers.empty')
    M = obj('mkSellers.data.meta')
    return section('mk_sellers', 'sellers.title', [
        rows,
        div('mt-4 flex items-center justify-between gap-3 flex-wrap', [
            span(XS_MUTED, e("(" + M + ".total || 0) + ' · ' + (" + M + ".page || 1) + ' / ' + (" + M + ".last_page || 1)")),
            C('Pagination', {'currentPage': e('Number(' + M + '.page || 1)'), 'totalPages': e('Number(' + M + '.last_page || 1)'),
                             'maxVisiblePages': 7},
              actions=[{'event': 'onPageChange', 'type': 'change', 'handler': 'navigate',
                        'params': {'path': MAIN_PATH, 'mergeQuery': True, 'query': {'page': e('$args[0]')}}}],
              **{'if': e('Number(' + M + '.last_page || 1) > 1')}),
        ]),
    ], right=toolbar, desc_key='sellers.desc')


def listings_table(src):
    return table(arr(src), [
        (None, [rank_badge()], 'left'),
        ('col.listing', [div('min-w-0', [
            B('A', {'href': e('row.public_url'), 'target': '_blank', 'className': LINK}, text=e('row.title'), **{'if': e('!!row.public_url')}),
            span('font-medium text-gray-700 dark:text-gray-200', e('row.title'), **{'if': e('!row.public_url')}),
            div('text-xs text-gray-400 dark:text-gray-500', text=e("row.type_label + (row.category ? ' · ' + row.category : '') + ' · ' + row.status_label")),
        ])], 'left'),
        ('col.seller', member_cell('row.seller', with_buttons=False), 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.quantity', e('row.quantity'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.views', e('row.views'), 'right'),
        ('col.conversion', e("(row.conversion || 0) + '%'"), 'right'),
        ('col.share', [share_bar()], 'left'),
    ])


def market_buyers_table(src):
    return table(arr(src), [
        (None, [rank_badge()], 'left'),
        ('col.member', member_cell('row.member'), 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.sellers', e('row.sellers'), 'right'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.last_at', e("row.last_at || '-'"), 'right'),
    ])


def market_breakdowns(src, prefix):
    return div('grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-6', [
        donut_card(prefix + '_types', 'market.types', arr(src + '.types')),
        donut_card(prefix + '_payment_modes', 'market.payment_modes', arr(src + '.payment_modes')),
        donut_card(prefix + '_trade_methods', 'market.trade_methods', arr(src + '.trade_methods')),
        donut_card(prefix + '_categories', 'market.categories', arr(src + '.categories')),
        donut_card(prefix + '_statuses', 'market.statuses', arr(src + '.statuses'), 'value'),
    ])


def market_datasets(S):
    return ("[{ label: " + tq('chart.gmv') + ", data: " + S + ".gmv || [], backgroundColor: '#10B981', borderRadius: 4 }, "
            "{ label: " + tq('chart.commission') + ", data: " + S + ".commission || [], backgroundColor: '#F59E0B', borderRadius: 4 }, "
            "{ label: " + tq('chart.orders') + ", data: " + S + ".orders || [], backgroundColor: '#A7F3D0', borderRadius: 4, yAxisID: 'y1' }]")


def tab_market():
    S = obj('mkSeries.data')
    settlements = table(arr('mkSettlements.data.rows'), [
        ('col.shop', [link_text(e("row.shop_name || '-'"), e('row.detail_url'))], 'left'),
        ('col.member', member_cell('row.member'), 'left'),
        ('col.orders', e('row.orders'), 'right'),
        ('col.settlement_pending', e('row.amount_formatted'), 'right'),
        ('col.commission', e('row.commission_formatted'), 'right'),
        ('col.oldest', e("row.oldest || '-'"), 'right'),
        (None, [div('flex justify-end', [seller_detail_button()])], 'right'),
    ], empty_key='market.settlements_empty')
    return partial_file('판매 통계 — 개인마켓 탭', div('flex flex-col gap-6', [
        market_snapshot('mkSummary.data.snapshot'),
        kpi_grid('mkSummary.data.kpis', MK_KPIS, 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4'),
        section('mk_trend', 'market.trend', [bar_chart(S + '.labels || []', market_datasets(S))], desc_key='market.trend_desc'),
        sellers_section(),
        section('mk_listings', 'market.listings', listings_table('mkListings.data.rows'), right=[csv_button('market', 'listings')]),
        section('mk_buyers', 'market.buyers', market_buyers_table('mkBuyers.data.rows'), right=[csv_button('market', 'buyers')]),
        market_breakdowns('mkBreakdowns.data', 'mk'),
        section('mk_settlements', 'market.settlements', settlements, desc_key='market.settlements_desc',
                right=[csv_button('market', 'settlements'),
                       nav_button('/admin/user-markets/orders', 'snapshot.go_settlements', 'arrow-right', {'settlement_status': 'pending'}, False)]),
    ], id='sales_stats_tab_market'))


def main_layout():
    P = 'partials/admin_sales_stats/'
    return {
        'version': VERSION,
        'layout_name': 'admin_sales_stats',
        'extends': '_admin_base',
        'permissions': [MOD + '.stats.view'],
        'meta': {'title': t('title'), 'description': t('description')},
        'data_sources': main_data_sources(),
        'slots': {'content': [
            div('admin-page-content-responsive', [
                div('flex flex-col gap-6', [
                    {'partial': P + '_header.json'},
                    {'partial': P + '_filters.json'},
                    {'partial': P + '_tabs.json'},
                    div(None, [{'partial': P + '_tab_overview.json'}], id='sales_stats_overview_wrap', **{'if': e(TAB + " === 'overview'")}),
                    div(None, [{'partial': P + '_tab_ecommerce.json'}], id='sales_stats_ecommerce_wrap', **{'if': e(TAB + " === 'ecommerce'")}),
                    div(None, [{'partial': P + '_tab_market.json'}], id='sales_stats_market_wrap', **{'if': e(TAB + " === 'market'")}),
                ]),
            ], id='sales_stats_page'),
        ]},
    }


# ───────────── 판매자 상세 ─────────────

SELLER_PATH = '/admin/sales-stats/sellers/{{route.userId}}'
SD = 'seller.data'
FOUND = '!!' + g(SD + '.profile')


def seller_data_sources():
    p = {**BASE_PARAMS, **MARKET_PARAMS}
    not_found = {'handler': 'toast', 'params': {'type': 'warning', 'message': t('seller.not_found')}}
    return [
        meta_ds(),
        ds('seller', API + '/market/sellers/{{route.userId}}', p, fallback={'data': {'missing': True}}, strategy='blocking',
           errors={'404': not_found,
                   '403': {'handler': 'toast', 'params': {'type': 'error', 'message': t('errors.forbidden')}},
                   'default': {'handler': 'toast', 'params': {'type': 'error', 'message': t('errors.load_failed')}}}),
        ds('sellerOrders', API + '/market/sellers/{{route.userId}}/orders', {**p, 'page': e("query.page || ''"), 'per_page': '20'},
           fallback={'data': {'rows': [], 'meta': {'last_page': 1}}},
           errors={'default': {'handler': 'toast', 'params': {'type': 'error', 'message': t('errors.load_failed')}}}),
    ]


def seller_header():
    P = obj(SD + '.profile')
    back_query = {'tab': 'market', **period_query()}
    return partial_file('판매자 상세 — 제목 · 뒤로 · 새로고침', div('flex items-start justify-between gap-4 flex-wrap', [
        div('min-w-0', [
            B('Button', {'type': 'button', 'className': 'inline-flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400 hover:underline mb-2'},
              [icon('arrow-left'), span(None, t('seller.back'))], actions=[navigate(MAIN_PATH, back_query, False)]),
            div('flex items-center gap-2', [icon('store', 'text-blue-600 dark:text-blue-400'),
                                            B('H1', {'className': 'text-2xl font-bold text-gray-900 dark:text-white'},
                                              text=e(P + '.shop_name || ' + tq('seller.title')))]),
            period_line(),
        ]),
        div('flex items-center gap-2 flex-wrap', [
            refresh_button(['meta', 'seller', 'sellerOrders']),
            csv_button('market', 'seller_orders', "'&seller=' + route.userId", label='seller.csv_orders'),
        ], **{'if': e(FOUND)}),
    ], id='seller_header'))


def seller_filters():
    return partial_file('판매자 상세 — 기간 · 기준 · 조건', filters_card(SELLER_PATH, e('true')))


def seller_profile():
    P = obj(SD + '.profile')
    info = lambda label, expr, cond=None: div('flex items-center justify-between gap-3 text-sm py-1', [  # noqa: E731
        span('text-gray-500 dark:text-gray-400', t(label)), span('text-gray-900 dark:text-white text-right', expr)], **{'if': cond})
    return partial_file('판매자 상세 — 프로필 카드', div(CARD + ' p-5', [
        div('flex items-start gap-4 flex-wrap', [
            B('Img', {'src': e(P + '.logo_url'), 'alt': '', 'className': 'w-16 h-16 rounded-full object-cover shrink-0'}, **{'if': e('!!' + P + '.logo_url')}),
            div('w-16 h-16 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center shrink-0',
                [icon('store', 'text-gray-400')], **{'if': e('!' + P + '.logo_url')}),
            div('flex-1 min-w-0', [
                div('flex items-center gap-2 flex-wrap', [
                    B('H2', {'className': 'text-lg font-semibold text-gray-900 dark:text-white'}, text=e(P + ".shop_name || '-'")),
                    span('inline-flex px-2 py-1 rounded-md text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200', e(P + '.seller_status_label')),
                    span('inline-flex px-2 py-1 rounded-md text-xs bg-amber-50 dark:bg-amber-900/30 text-amber-700 dark:text-amber-300',
                         t('seller.designated'), **{'if': e('!!' + P + '.is_designated')}),
                ]),
                B('P', {'className': 'mt-1 text-sm text-gray-600 dark:text-gray-300 whitespace-pre-line'}, text=e(P + '.intro'), **{'if': e('!!' + P + '.intro')}),
                B('P', {'className': 'mt-1 text-xs text-red-600 dark:text-red-400'}, text=e(P + '.status_reason'), **{'if': e('!!' + P + '.status_reason')}),
                div('mt-3 max-w-xs', member_cell(g(SD + '.profile.member'))),
                div('mt-3 flex items-center gap-2 flex-wrap', [
                    B('A', {'href': e(P + '.shop_url'), 'target': '_blank', 'className': BTN_XS},
                      [icon('arrow-up-right-from-square'), span(None, t('seller.shop'))]),
                ]),
            ]),
            div('w-full md:w-72 rounded-lg bg-gray-50 dark:bg-gray-700 p-3', [
                info('seller.joined_at', e(P + ".joined_at || '-'")),
                info('seller.approved_at', e(P + ".approved_at || '-'")),
                info('seller.rating', e(P + ".review_count > 0 ? ('★ ' + " + P + ".rating + ' (' + " + P + ".review_count + ')') : '-'")),
                info('seller.sales_count', e(P + '.sales_count || 0')),
                info('seller.settled_total', e(P + ".settled_total_formatted + ' (' + (" + P + ".settled_total_orders || 0) + ')'")),
                info('seller.last_settled_at', e(P + ".last_settled_at || '-'")),
                info('seller.pending_reports', e(P + '.pending_reports || 0')),
            ]),
        ]),
    ], id='seller_profile'))


def seller_body():
    S = obj(SD + '.timeseries')
    reviews = div('flex flex-col divide-y divide-gray-200 dark:divide-gray-700', [
        div('py-3 flex flex-col gap-1', [
            div('flex items-center justify-between gap-2', [
                span('text-amber-500', e('rv.stars')),
                span(XS_MUTED, e("rv.created_at || ''")),
            ]),
            B('P', {'className': 'text-sm text-gray-700 dark:text-gray-200'}, text=e("rv.content || ''")),
            div('flex items-center gap-2', [
                div('min-w-0 flex-1', member_cell('rv.reviewer', with_buttons=False)),
                span('text-xs text-gray-400 dark:text-gray-500', t('seller.review_hidden'), **{'if': e('!!rv.is_hidden')}),
            ]),
        ], iteration=iterate(arr(SD + '.reviews'), 'rv', 'ri')),
    ], **{'if': e(arr(SD + '.reviews') + '.length > 0')})
    O = obj('sellerOrders.data.meta')
    orders = table(arr('sellerOrders.data.rows'), [
        ('col.order_no', [link_text(e("row.order_no || ('#' + row.id)"), e('row.admin_url'))], 'left'),
        ('col.ordered_at', e("row.created_at || '-'"), 'left'),
        ('col.listing', [div('min-w-0', [span('text-gray-900 dark:text-white', e("row.title || '-'")),
                                         div('text-xs text-gray-400 dark:text-gray-500', text=e("row.type_label + ' · ' + row.payment_mode_label"))])], 'left'),
        ('col.buyer', member_cell('row.buyer'), 'left'),
        ('col.amount', e('row.amount_formatted'), 'right'),
        ('col.commission', e('row.commission_formatted'), 'right'),
        ('col.payout', e('row.payout_formatted'), 'right'),
        ('col.status', [span('inline-flex px-2 py-1 rounded-md text-xs bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-200', e('row.status_label'))], 'left'),
        ('col.settlement', e('row.settlement_label'), 'left'),
    ], empty_key='seller.orders_empty')
    return partial_file('판매자 상세 — KPI · 추이 · 상품 · 구매자 · 분포 · 후기 · 주문', div('flex flex-col gap-6', [
        market_snapshot(SD + '.summary.snapshot', seller=True),
        kpi_grid(SD + '.summary.kpis', SELLER_KPIS, 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4'),
        section('seller_trend', 'market.trend', [bar_chart(S + '.labels || []', market_datasets(S))]),
        div('grid grid-cols-1 xl:grid-cols-2 gap-6', [
            section('seller_listings', 'market.listings', listings_table(SD + '.listings'),
                    right=[csv_button('market', 'listings', "'&seller=' + route.userId")]),
            section('seller_buyers', 'market.buyers', market_buyers_table(SD + '.buyers')),
        ]),
        market_breakdowns(SD + '.breakdowns', 'seller'),
        section('seller_reviews', 'seller.reviews', [reviews, empty('seller.reviews_empty', 'star', cond=e(arr(SD + '.reviews') + '.length === 0'))]),
        section('seller_orders', 'seller.orders', [
            orders,
            div('mt-4 flex items-center justify-between gap-3 flex-wrap', [
                span(XS_MUTED, e("(" + O + ".total || 0) + ' · ' + (" + O + ".page || 1) + ' / ' + (" + O + ".last_page || 1)")),
                C('Pagination', {'currentPage': e('Number(' + O + '.page || 1)'), 'totalPages': e('Number(' + O + '.last_page || 1)'),
                                 'maxVisiblePages': 7},
                  actions=[{'event': 'onPageChange', 'type': 'change', 'handler': 'navigate',
                            'params': {'path': SELLER_PATH, 'mergeQuery': True, 'query': {'page': e('$args[0]')}}}],
                  **{'if': e('Number(' + O + '.last_page || 1) > 1')}),
            ]),
        ], desc_key='seller.orders_desc', right=[csv_button('market', 'seller_orders', "'&seller=' + route.userId")]),
    ], id='seller_body'))


def seller_layout():
    P = 'partials/admin_sales_stats_seller/'
    return {
        'version': VERSION,
        'layout_name': 'admin_sales_stats_seller',
        'extends': '_admin_base',
        'permissions': [MOD + '.stats.view'],
        'meta': {'title': t('seller.title'), 'description': t('seller.description')},
        'data_sources': seller_data_sources(),
        'slots': {'content': [
            div('admin-page-content-responsive', [
                div('flex flex-col gap-6', [
                    {'partial': P + '_header.json'},
                    div(CARD + ' p-6 flex flex-col items-center gap-4', [
                        C('EmptyState', {'title': t('seller.not_found_title'), 'description': t('seller.not_found'), 'iconName': 'store'}),
                        B('Button', {'type': 'button', 'className': BTN}, [icon('arrow-left'), span(None, t('seller.back'))],
                          actions=[navigate(MAIN_PATH, {'tab': 'market', **period_query()}, False)]),
                    ], id='seller_not_found', **{'if': e('!' + FOUND)}),
                    div('flex flex-col gap-6', [
                        {'partial': P + '_filters.json'},
                        {'partial': P + '_profile.json'},
                        {'partial': P + '_body.json'},
                    ], id='seller_found', **{'if': e(FOUND)}),
                ]),
            ], id='seller_page'),
        ]},
    }


def main():
    files = {
        'admin_sales_stats.json': main_layout(),
        'partials/admin_sales_stats/_header.json': main_header(),
        'partials/admin_sales_stats/_filters.json': partial_file('판매 통계 — 기간 · 기준 · 조건',
                                                                  filters_card(MAIN_PATH, e(TAB + " === 'market'"))),
        'partials/admin_sales_stats/_tabs.json': main_tabs(),
        'partials/admin_sales_stats/_tab_overview.json': tab_overview(),
        'partials/admin_sales_stats/_tab_ecommerce.json': tab_ecommerce(),
        'partials/admin_sales_stats/_tab_market.json': tab_market(),
        'admin_sales_stats_seller.json': seller_layout(),
        'partials/admin_sales_stats_seller/_header.json': seller_header(),
        'partials/admin_sales_stats_seller/_filters.json': seller_filters(),
        'partials/admin_sales_stats_seller/_profile.json': seller_profile(),
        'partials/admin_sales_stats_seller/_body.json': seller_body(),
    }
    for rel, data in files.items():
        path = os.path.join(LAYOUT_DIR, rel)
        os.makedirs(os.path.dirname(path), exist_ok=True)
        with open(path, 'w', encoding='utf-8') as fh:
            json.dump(data, fh, ensure_ascii=False, indent=2)
            fh.write('\n')
        print('wrote', os.path.relpath(path, ROOT))


if __name__ == '__main__':
    main()
