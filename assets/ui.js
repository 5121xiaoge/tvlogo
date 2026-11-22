/*!
 * 直播源工具箱 · 统一界面增强层（无依赖，约 4KB）
 * 在 <head> 中、页面自身脚本之前引入：
 *   <script src="../assets/ui.js" data-home="../"></script>
 * 可选属性：
 *   data-home   返回工具箱的地址；不设置则不显示"返回工具箱"
 *   data-player 播放器页面（返回按钮放进顶栏按钮组，触控尺寸交给播放器自己控制）
 * 调试：地址后加 ?debug=1 可恢复 console.log 输出
 */
(function () {
  'use strict';
  var me = document.currentScript || {};
  var cfg = (me && me.dataset) || {};
  var isPlayer = 'player' in cfg;

  /* ---------- 生产环境静默 console.log / debug（保留 warn / error） ---------- */
  try {
    if (!/[?&]debug=1\b/.test(location.search)) {
      var noop = function () {};
      console.log = noop; console.debug = noop; console.info = noop;
    }
  } catch (e) {}

  /* ---------- 轻提示：替代阻塞式 alert()（confirm 仍为原生，保证流程不变） ---------- */
  var wrap;
  function toast(msg, ms) {
    if (!document.body) { return nativeAlert(msg); }
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'ui-toast-wrap';
      wrap.setAttribute('role', 'status');
      wrap.setAttribute('aria-live', 'polite');
      document.body.appendChild(wrap);
    }
    var el = document.createElement('div');
    el.className = 'ui-toast';
    var text = document.createElement('span');
    text.textContent = String(msg == null ? '' : msg);
    var close = document.createElement('button');
    close.type = 'button'; close.setAttribute('aria-label', '关闭'); close.textContent = '×';
    el.appendChild(text); el.appendChild(close);
    wrap.appendChild(el);
    while (wrap.children.length > 4) wrap.removeChild(wrap.firstChild);
    var len = text.textContent.length;
    var t = setTimeout(hide, ms || Math.min(8000, 2600 + len * 60));
    function hide() {
      clearTimeout(t);
      el.classList.add('out');
      setTimeout(function () { el.remove(); }, 220);
    }
    close.addEventListener('click', hide);
  }
  var nativeAlert = window.alert.bind(window);
  window.uiToast = toast;
  window.alert = function (msg) { toast(msg); };

  /* ---------- DOM 增强 ---------- */
  function enhance() {
    var body = document.body;
    body.classList.add('ui-ready');
    if (isPlayer) body.classList.add('ui-player');

    // 1. 按钮默认 type=button，避免将来包进 <form> 时误提交
    document.querySelectorAll('button:not([type])').forEach(function (b) { b.type = 'button'; });

    // 2. 只有图标的按钮补充无障碍名称
    document.querySelectorAll('button, a.btn, [role="button"]').forEach(function (b) {
      if (b.getAttribute('aria-label') || b.textContent.trim()) return;
      var name = b.getAttribute('title') || b.dataset.tooltip || (b.id || '').replace(/[-_]?btn$/i, '').replace(/([a-z])([A-Z])/g, '$1 $2');
      if (name) b.setAttribute('aria-label', name);
    });

    // 3. 没有 <label> 的输入框，用占位符 / 相邻文字作为无障碍名称
    document.querySelectorAll('input:not([type="hidden"]), select, textarea').forEach(function (el) {
      if (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby')) return;
      if (el.id && document.querySelector('label[for="' + CSS.escape(el.id) + '"]')) return;
      if (el.closest('label')) return;
      var name = el.getAttribute('placeholder') || el.getAttribute('title');
      if (!name) {
        var prev = el.previousElementSibling;
        if (prev && prev.textContent.trim().length < 40) name = prev.textContent.trim();
      }
      if (name) el.setAttribute('aria-label', name);
    });

    // 4. 表格放进横向滚动容器，手机上不撑破页面
    document.querySelectorAll('table').forEach(function (tb) {
      var p = tb.parentElement;
      if (!p || p.classList.contains('ui-table-scroll')) return;
      var ox = getComputedStyle(p).overflowX;
      if (ox === 'auto' || ox === 'scroll') return;
      var w = document.createElement('div');
      w.className = 'ui-table-scroll';
      p.insertBefore(w, tb); w.appendChild(tb);
    });

    // 5. 跳转到主要内容
    var header = document.querySelector('header');
    var main = document.querySelector('main') || (header && header.nextElementSibling);
    if (main) {
      if (!main.id) main.id = 'main-content';
      if (!main.hasAttribute('tabindex')) main.setAttribute('tabindex', '-1');
      var skip = document.createElement('a');
      skip.className = 'ui-skip'; skip.href = '#' + main.id; skip.textContent = '跳到主要内容';
      body.insertBefore(skip, body.firstChild);
    }

    // 6. 返回工具箱
    if (cfg.home && header) {
      var home = document.createElement('a');
      home.href = cfg.home;
      home.setAttribute('aria-label', '返回工具箱首页');
      home.title = '返回工具箱首页';
      if (isPlayer) {
        var actions = header.querySelector('.header-actions');
        home.className = 'btn btn-icon btn-secondary';
        home.innerHTML = '<i class="fas fa-home" aria-hidden="true"></i>';
        if (actions) actions.insertBefore(home, actions.firstChild);
      } else {
        home.className = 'ui-home';
        home.innerHTML = '<span aria-hidden="true">←</span> 工具箱';
        var row = document.createElement('div');
        row.className = 'ui-home-row';
        row.appendChild(home);
        header.insertBefore(row, header.firstChild);
      }
    }

    // 7. 外部链接加提示与安全属性
    document.querySelectorAll('a[href^="http"]').forEach(function (a) {
      if (a.host === location.host) return;
      var rel = (a.getAttribute('rel') || '').split(/\s+/);
      ['noopener', 'noreferrer'].forEach(function (r) { if (rel.indexOf(r) < 0) rel.push(r); });
      a.setAttribute('rel', rel.join(' ').trim());
      if (!a.title) a.title = '外部站点：' + a.host;
    });

    // 8. 页脚年份自动更新
    document.querySelectorAll('[data-year]').forEach(function (el) { el.textContent = new Date().getFullYear(); });

    // 9. 回到顶部（长页面）
    if (!isPlayer) {
      var top = document.createElement('button');
      top.type = 'button'; top.className = 'ui-top'; top.setAttribute('aria-label', '回到顶部'); top.title = '回到顶部';
      top.innerHTML = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>';
      top.addEventListener('click', function () { window.scrollTo({ top: 0, behavior: 'smooth' }); });
      body.appendChild(top);
      var ticking = false;
      window.addEventListener('scroll', function () {
        if (ticking) return; ticking = true;
        requestAnimationFrame(function () { top.classList.toggle('show', window.scrollY > 600); ticking = false; });
      }, { passive: true });
    }
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', enhance);
  else enhance();
})();
