/*! custom-sales_stats — 판매 통계 관리자 화면 핸들러
 * 빌드 산출물(dist/js/module.iife.js)은 이 파일을 그대로 복사합니다: node scripts/build.mjs
 *
 * 핸들러
 * - custom-sales_stats.composeNote { uuid, name } : 쪽지 모듈(custom-note)의 쓰기 창을 엽니다.
 *     window.__G7Note.compose(uuid) 가 없으면(쪽지 모듈 없음/꺼짐) 안내 후 회원 상세로 이동합니다.
 * - custom-sales_stats.openMember { uuid } : 관리자 회원 상세(/admin/users/{uuid}) 로 이동합니다.
 *
 * 다른 모듈의 코드는 호출만 하고(있을 때만), 바꾸지 않습니다.
 */
(function () {
  'use strict';
  var ID = 'custom-sales_stats';
  var UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

  function params(action) {
    return (action && action.params) || action || {};
  }

  function t(key, fallback) {
    try {
      var core = window.G7Core;
      var v = core && typeof core.t === 'function' ? core.t(ID + '.' + key) : '';
      return v && v !== ID + '.' + key ? v : fallback;
    } catch (e) {
      return fallback;
    }
  }

  function toast(kind, message) {
    try {
      var tt = window.G7Core && window.G7Core.toast;
      if (tt && typeof tt[kind] === 'function') { tt[kind](message); return; }
    } catch (e) { /* ignore */ }
  }

  function navigate(path) {
    try {
      if (window.G7Core && typeof window.G7Core.dispatch === 'function') {
        window.G7Core.dispatch({ handler: 'navigate', params: { path: path } });
        return;
      }
    } catch (e) { /* fall through */ }
    window.location.href = path;
  }

  function openMember(action) {
    var uuid = String(params(action).uuid || '').trim();
    if (!UUID_RE.test(uuid)) { toast('warning', t('js.no_member', '회원 정보를 열 수 없습니다 (비회원·탈퇴 회원).')); return; }
    navigate('/admin/users/' + uuid);
  }

  function composeNote(action) {
    var p = params(action);
    var uuid = String(p.uuid || '').trim();
    if (!UUID_RE.test(uuid)) { toast('warning', t('js.no_member', '회원 정보를 열 수 없습니다 (비회원·탈퇴 회원).')); return; }
    var note = window.__G7Note;
    if (note && typeof note.compose === 'function') {
      try { note.compose(uuid); return; } catch (e) { /* fall through */ }
    }
    toast('info', t('js.note_unavailable', '쪽지 모듈이 꺼져 있어 회원 상세로 이동합니다.'));
    navigate('/admin/users/' + uuid);
  }

  var handlers = { composeNote: composeNote, openMember: openMember };

  function register(attempt) {
    var core = window.G7Core;
    var dispatcher = core && typeof core.getActionDispatcher === 'function' ? core.getActionDispatcher() : null;
    if (dispatcher && typeof dispatcher.registerHandler === 'function') {
      Object.keys(handlers).forEach(function (name) {
        dispatcher.registerHandler(ID + '.' + name, function (action) { return handlers[name](action); });
      });
      return;
    }
    if ((attempt || 0) < 100) setTimeout(function () { register((attempt || 0) + 1); }, 100);
  }

  function initModule() { register(0); }

  // 코어 재초기화(언어 전환 등) 때 재등록 진입점 — 핸들러 등록만 합니다.
  window.__CustomSalesStats = { identifier: ID, initModule: initModule };

  initModule();
})();
