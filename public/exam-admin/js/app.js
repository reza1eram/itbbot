/* Shared admin helpers. No inline scripts are used (CSP: script-src 'self'). */
(function () {
  'use strict';

  var csrf = (document.querySelector('meta[name="csrf-token"]') || {}).content;

  /** JSON fetch with CSRF; resolves to {ok, status, data} */
  window.axApi = function (method, url, body) {
    var opts = {
      method: method,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' }
    };
    if (body !== undefined) { opts.headers['Content-Type'] = 'application/json'; opts.body = JSON.stringify(body); }
    return fetch(url, opts).then(function (r) {
      return r.json().catch(function () { return {}; }).then(function (data) {
        if (r.status === 401) { window.location.reload(); }
        return { ok: r.ok, status: r.status, data: data };
      });
    }).catch(function () { return { ok: false, status: 0, data: { message: 'ارتباط با سرور برقرار نشد.' } }; });
  };

  // mobile sidebar
  var toggle = document.querySelector('[data-toggle-sidebar]');
  if (toggle) toggle.addEventListener('click', function () { document.getElementById('sidebar').classList.toggle('open'); });

  // <form data-confirm="..."> simple confirmation
  document.querySelectorAll('form[data-confirm]').forEach(function (f) {
    f.addEventListener('submit', function (e) { if (!confirm(f.dataset.confirm)) e.preventDefault(); });
  });

  // two-step confirmation: <form data-confirm-code="abc"> must contain input[name=confirm_code]
  document.querySelectorAll('form[data-confirm-code]').forEach(function (f) {
    var input = f.querySelector('input[name="confirm_code"]');
    var btn = f.querySelector('button[type="submit"]');
    if (!input || !btn) return;
    var check = function () { btn.disabled = input.value.trim().toLowerCase() !== f.dataset.confirmCode; };
    input.addEventListener('input', check); check();
    f.addEventListener('submit', function (e) {
      if (btn.disabled || !confirm('آیا مطمئن هستید؟ پس از پایان، هیچ پاسخ جدیدی پذیرفته نمی‌شود.')) e.preventDefault();
    });
  });

  // CSS bar charts: widths / heights from data attributes (inline styles are blocked by CSP)
  document.querySelectorAll('[data-pct]').forEach(function (b) {
    var v = Math.max(0, Math.min(100, parseFloat(b.dataset.pct) || 0));
    requestAnimationFrame(function () { b.style.width = v + '%'; });
  });
  document.querySelectorAll('[data-hpct]').forEach(function (b) {
    var v = Math.max(0, Math.min(100, parseFloat(b.dataset.hpct) || 0));
    requestAnimationFrame(function () { b.style.height = v + '%'; });
  });

  // live participation counts on the dashboard / run page
  var live = document.querySelector('[data-live-url]');
  if (live) {
    var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
    setInterval(function () {
      if (document.hidden) return;
      window.axApi('GET', live.dataset.liveUrl).then(function (res) {
        if (!res.ok) return;
        (res.data.exams || []).forEach(function (e) {
          document.querySelectorAll('[data-live-count="' + e.id + '"]').forEach(function (n) { n.textContent = fa(e.submitted); });
        });
      });
    }, 15000);
  }

  // copy buttons
  document.querySelectorAll('[data-copy]').forEach(function (b) {
    b.addEventListener('click', function () {
      if (navigator.clipboard) navigator.clipboard.writeText(b.dataset.copy).then(function () { b.textContent = 'کپی شد ✓'; });
    });
  });
})();
