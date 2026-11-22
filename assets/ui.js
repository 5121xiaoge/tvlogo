/*!
 * 直播源工具箱 · 统一界面增强层（无依赖，约 4KB）
 * 在 <head> 中、页面自身脚本之前引入：
 *   <script src="../assets/ui.js" data-home="../"></script>
 * 可选属性：
 *   data-home   返回工具箱的地址；不设置则不显示"返回工具箱"
 *   data-player 播放器页面（返回按钮放进顶栏按钮组，触控尺寸交给播放器自己控制）
 *   data-page   页面标识（home / resp / scan / socks5 / val / txt2m3u / player），供 theme.css 使用
 * 对外接口：window.uiToast(msg, ms, type)、window.uiConfirm(msg, opts) → Promise<boolean>
 * 调试：地址后加 ?debug=1 可恢复 console.log 输出
 */
(function () {
  'use strict';
  var me = document.currentScript || {};
  var cfg = (me && me.dataset) || {};
  var isPlayer = 'player' in cfg;

  /* ---------- 页面标识：在 <head> 阶段写到 <html> 上，主题样式无闪烁生效 ---------- */
  var page = cfg.page || (isPlayer ? 'player' : '');
  var rootEl = document.documentElement;
  if (page) rootEl.setAttribute('data-page', page);
  if (/^(resp|scan|socks5|val|txt2m3u)$/.test(page)) rootEl.classList.add('ui-tool');

  /* ---------- 生产环境静默 console.log / debug（保留 warn / error） ---------- */
  try {
    if (!/[?&]debug=1\b/.test(location.search)) {
      var noop = function () {};
      console.log = noop; console.debug = noop; console.info = noop;
    }
  } catch (e) {}

  /* ---------- 图标 ---------- */
  var ICONS = {
    info: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a.75.75 0 000 1.5h.25v3.25a.75.75 0 001.5 0V9.75A.75.75 0 0010 9H9z" clip-rule="evenodd"/></svg>',
    success: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.86-9.81a.75.75 0 00-1.22-.88l-3.24 4.5-1.6-1.6a.75.75 0 10-1.06 1.06l2.22 2.22a.75.75 0 001.14-.09l3.76-5.21z" clip-rule="evenodd"/></svg>',
    warning: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M8.49 2.87c.66-1.16 2.36-1.16 3.02 0l6.28 11.1c.65 1.15-.18 2.53-1.51 2.53H3.72c-1.33 0-2.16-1.38-1.51-2.53l6.28-11.1zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 8a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>',
    error: '<svg viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd"/></svg>'
  };
  function guessType(s) {
    if (/失败|错误|异常|无法|丢失|不支持|出错/.test(s)) return 'error';
    if (/成功|已复制|已保存|已导出|已加载|完成/.test(s)) return 'success';
    if (/请|没有|未|不能|⚠/.test(s)) return 'warning';
    return 'info';
  }

  /* ---------- 轻提示：替代阻塞式 alert() ---------- */
  var wrap;
  function toast(msg, ms, type) {
    if (!document.body) { return nativeAlert(msg); }
    if (!wrap) {
      wrap = document.createElement('div');
      wrap.className = 'ui-toast-wrap';
      wrap.setAttribute('role', 'status');
      wrap.setAttribute('aria-live', 'polite');
      document.body.appendChild(wrap);
    }
    var str = String(msg == null ? '' : msg).replace(/^\s*⚠️?\s*/, '');
    type = ICONS[type] ? type : guessType(str);
    var el = document.createElement('div');
    el.className = 'ui-toast ui-toast-' + type;
    var icon = document.createElement('span');
    icon.className = 'ui-toast-icon'; icon.innerHTML = ICONS[type];
    var text = document.createElement('span');
    text.className = 'ui-toast-text'; text.textContent = str;
    var close = document.createElement('button');
    close.type = 'button'; close.className = 'ui-toast-close'; close.setAttribute('aria-label', '关闭'); close.textContent = '×';
    el.appendChild(icon); el.appendChild(text); el.appendChild(close);
    wrap.appendChild(el);
    while (wrap.children.length > 4) wrap.removeChild(wrap.firstChild);
    var t = setTimeout(hide, ms || Math.min(8000, 2600 + str.length * 60));
    function hide() {
      clearTimeout(t);
      el.classList.add('out');
      setTimeout(function () { el.remove(); }, 220);
    }
    close.addEventListener('click', hide);
  }
  var nativeAlert = window.alert.bind(window);
  var nativeConfirm = window.confirm.bind(window);
  window.uiToast = toast;
  window.alert = function (msg) { toast(msg); };

  /* ---------- 确认对话框：替代原生 confirm()，返回 Promise<boolean> ---------- */
  function uiConfirm(message, opts) {
    opts = opts || {};
    message = String(message == null ? '' : message);
    return new Promise(function (resolve) {
      if (!document.body) { resolve(nativeConfirm(message)); return; }
      var danger = opts.danger != null ? !!opts.danger : /删除|清空|清除|移除|不可恢复/.test(message);
      var prevFocus = document.activeElement;
      var uid = 'ui-dlg-' + Date.now();

      var ov = document.createElement('div');
      ov.className = 'ui-dialog-backdrop';
      var dlg = document.createElement('div');
      dlg.className = 'ui-dialog' + (danger ? ' is-danger' : '');
      dlg.setAttribute('role', 'alertdialog');
      dlg.setAttribute('aria-modal', 'true');
      dlg.setAttribute('aria-labelledby', uid + '-t');
      dlg.setAttribute('aria-describedby', uid + '-m');

      // 拆出"此操作不可恢复"等补充说明
      var main = message, note = '';
      var m = message.match(/^(.*?[？?])\s*(.+)$/);
      if (m) { main = m[1]; note = m[2]; }

      dlg.innerHTML =
        '<div class="ui-dialog-icon">' + (danger ? ICONS.warning : ICONS.info) + '</div>' +
        '<div class="ui-dialog-body">' +
          '<h2 class="ui-dialog-title" id="' + uid + '-t"></h2>' +
          '<p class="ui-dialog-msg" id="' + uid + '-m"></p>' +
        '</div>' +
        '<div class="ui-dialog-actions">' +
          '<button type="button" class="ui-dialog-btn" data-act="cancel"></button>' +
          '<button type="button" class="ui-dialog-btn ' + (danger ? 'is-danger' : 'is-primary') + '" data-act="ok"></button>' +
        '</div>';
      dlg.querySelector('.ui-dialog-title').textContent = opts.title || main;
      var msgEl = dlg.querySelector('.ui-dialog-msg');
      if (opts.title) msgEl.textContent = message;
      else if (note) msgEl.textContent = note;
      else msgEl.textContent = danger ? '该操作执行后将无法撤销，请确认后继续。' : '请确认是否继续。';
      var btnCancel = dlg.querySelector('[data-act="cancel"]');
      var btnOk = dlg.querySelector('[data-act="ok"]');
      btnCancel.textContent = opts.cancelText || '取消';
      btnOk.textContent = opts.okText || (danger ? '确认清除' : '确定');
      if (danger && /删除/.test(message) && !opts.okText) btnOk.textContent = '确认删除';

      ov.appendChild(dlg);
      document.body.appendChild(ov);
      rootEl.classList.add('ui-dialog-open');
      requestAnimationFrame(function () { ov.classList.add('in'); });
      (danger ? btnCancel : btnOk).focus();

      var done = false;
      function close(result) {
        if (done) return; done = true;
        document.removeEventListener('keydown', onKey, true);
        ov.classList.remove('in'); ov.classList.add('out');
        setTimeout(function () {
          ov.remove();
          if (!document.querySelector('.ui-dialog-backdrop')) rootEl.classList.remove('ui-dialog-open');
          try { prevFocus && prevFocus.focus && prevFocus.focus({ preventScroll: true }); } catch (e) {}
        }, 160);
        resolve(result);
      }
      function onKey(e) {
        if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); close(false); }
        else if (e.key === 'Tab') {
          e.preventDefault();
          (document.activeElement === btnOk ? btnCancel : btnOk).focus();
        }
      }
      document.addEventListener('keydown', onKey, true);
      btnCancel.addEventListener('click', function () { close(false); });
      btnOk.addEventListener('click', function () { close(true); });
      ov.addEventListener('mousedown', function (e) { if (e.target === ov) close(false); });
    });
  }
  window.uiConfirm = uiConfirm;

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

    // 播放器：倍速并入画面内控制条，画面外重复的播放/进度/音量按钮由样式隐藏
    if (isPlayer) {
      var ov = document.getElementById('inVideoControls');
      var speed = document.getElementById('speedDropdown');
      var fsBtn = document.getElementById('inVideoFullscreenBtn');
      if (ov && speed && fsBtn) {
        ov.insertBefore(speed, fsBtn);
        rootEl.classList.add('ui-overlay-ctrl');
        // 进度条已播放部分着色（播放器脚本直接改 value，不触发事件，这里定时同步）
        var seek = document.getElementById('inVideoSeekSlider');
        if (seek) setInterval(function () {
          if (!ov.classList.contains('controls-visible')) return;
          var max = parseFloat(seek.max) || 0, val = parseFloat(seek.value) || 0;
          seek.style.setProperty('--seek-pct', (max > 0 ? Math.min(100, val / max * 100) : 0).toFixed(2) + '%');
        }, 250);
      }
      // 触屏：控制条隐藏时，第一次点画面只呼出控制条，不触发暂停
      var lastPointer = '';
      document.addEventListener('pointerdown', function (e) { lastPointer = e.pointerType; }, true);
      document.addEventListener('click', function (e) {
        if (lastPointer !== 'touch' || !ov) return;
        var vc = document.getElementById('videoContainer');
        if (!vc || !vc.contains(e.target) || ov.contains(e.target)) return;
        if (!ov.classList.contains('controls-visible') && typeof window.showControls === 'function') {
          e.stopPropagation(); e.preventDefault();
          window.showControls();
        }
      }, true);
    }

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
