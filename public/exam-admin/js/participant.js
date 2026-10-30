/* Participant form: local draft (localStorage only), stepwise mode, progress, timer, confirm, submit. */
(function () {
  'use strict';

  function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function latin(s) { return String(s).replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); }).replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace('٫', '.'); }
  function store(fn) { try { return fn(window.localStorage); } catch (e) { return null; } }

  // After a successful submit: wipe every local draft of this module on this device
  if (document.getElementById('px-done')) {
    store(function (ls) {
      Object.keys(ls).filter(function (k) { return k.indexOf('ax-draft-') === 0; }).forEach(function (k) { ls.removeItem(k); });
    });
    return;
  }

  var form = document.getElementById('px-form');
  if (!form) return;

  var csrf = document.querySelector('meta[name="csrf-token"]').content;
  var questions = Array.prototype.slice.call(form.querySelectorAll('.question'));
  var alertBox = document.getElementById('px-alert');
  var draftKey = form.dataset.draftKey;
  var stepwise = form.dataset.mode === 'stepwise';
  var step = 0;
  var submitting = false;

  // ---------- answers ----------
  function valueOf(q) {
    var name = 'q' + q.dataset.qid;
    var type = q.dataset.type;
    if (type === 'multi') {
      return Array.prototype.slice.call(q.querySelectorAll('input[name="' + name + '"]:checked')).map(function (i) { return i.value; });
    }
    if (type === 'single' || type === 'true_false' || type === 'rating') {
      var c = q.querySelector('input[name="' + name + '"]:checked');
      return c ? c.value : '';
    }
    var f = q.querySelector('[name="' + name + '"]');
    return f ? (type === 'number' ? latin(f.value.trim()) : f.value) : '';
  }
  function collect() {
    var out = {};
    questions.forEach(function (q) {
      var v = valueOf(q);
      if (v !== '' && !(Array.isArray(v) && !v.length)) out[q.dataset.qid] = v;
    });
    return out;
  }
  function isAnswered(q) { var v = valueOf(q); return Array.isArray(v) ? v.length > 0 : String(v).trim() !== ''; }

  // ---------- local draft (never sent to the server until submit) ----------
  function saveDraft() {
    store(function (ls) { ls.setItem(draftKey, JSON.stringify(collect())); });
    document.getElementById('px-draft-state').textContent = 'پیش‌نویس روی همین دستگاه ذخیره شد.';
  }
  function restoreDraft() {
    var raw = store(function (ls) { return ls.getItem(draftKey); });
    if (!raw) return;
    var data; try { data = JSON.parse(raw); } catch (e) { return; }
    questions.forEach(function (q) {
      var v = data[q.dataset.qid];
      if (v === undefined) return;
      var name = 'q' + q.dataset.qid;
      if (Array.isArray(v)) {
        q.querySelectorAll('input[name="' + name + '"]').forEach(function (i) { i.checked = v.indexOf(i.value) >= 0; });
      } else {
        var radio = q.querySelector('input[type=radio][name="' + name + '"][value="' + String(v).replace(/"/g, '') + '"]');
        if (radio) radio.checked = true;
        var f = q.querySelector('input[type=text][name="' + name + '"], textarea[name="' + name + '"]');
        if (f) f.value = v;
      }
    });
  }
  function clearDraft() { store(function (ls) { ls.removeItem(draftKey); }); }

  // ---------- progress / counters ----------
  function updateProgress() {
    var done = questions.filter(isAnswered).length;
    var pct = questions.length ? Math.round(done * 100 / questions.length) : 0;
    document.getElementById('px-progress').style.width = pct + '%';
    document.getElementById('px-progress-text').textContent = stepwise
      ? 'سؤال ' + fa(step + 1) + ' از ' + fa(questions.length)
      : fa(done) + ' از ' + fa(questions.length) + ' پاسخ داده شده';
    form.querySelectorAll('[data-counter-for]').forEach(function (c) {
      var f = form.querySelector('[name="' + c.dataset.counterFor + '"]');
      if (f) c.textContent = fa(f.value.length) + ' / ' + fa(f.maxLength);
    });
  }

  form.addEventListener('input', function () { clearErrors(); saveDraft(); updateProgress(); });
  form.addEventListener('change', function () { saveDraft(); updateProgress(); });

  // ---------- validation (the server validates again) ----------
  function setError(q, msg) {
    q.classList.add('has-error');
    var e = q.querySelector('.q-error'); e.textContent = msg; e.classList.remove('hidden');
  }
  function clearErrors() {
    questions.forEach(function (q) { q.classList.remove('has-error'); q.querySelector('.q-error').classList.add('hidden'); });
    alertBox.classList.add('hidden');
  }
  function check(q) {
    if (q.dataset.required === '1' && !isAnswered(q)) { setError(q, 'پاسخ به این سؤال الزامی است.'); return false; }
    if (q.dataset.type === 'number' && isAnswered(q)) {
      var input = q.querySelector('input'); var n = Number(latin(input.value.trim()));
      if (isNaN(n)) { setError(q, 'یک عدد معتبر وارد کنید.'); return false; }
      if (input.dataset.min !== undefined && n < Number(input.dataset.min)) { setError(q, 'عدد کمتر از حد مجاز است.'); return false; }
      if (input.dataset.max !== undefined && n > Number(input.dataset.max)) { setError(q, 'عدد بیشتر از حد مجاز است.'); return false; }
    }
    return true;
  }

  // ---------- stepwise ----------
  var prev = document.getElementById('px-prev');
  var next = document.getElementById('px-next');
  var submitBtn = document.getElementById('px-submit');
  function showStep() {
    if (!stepwise) return;
    questions.forEach(function (q, i) { q.classList.toggle('hidden', i !== step); });
    prev.classList.toggle('hidden', step === 0);
    var last = step === questions.length - 1;
    next.classList.toggle('hidden', last);
    submitBtn.classList.toggle('hidden', !last);
    updateProgress();
    window.scrollTo({ top: 0, behavior: 'smooth' });
  }
  prev.addEventListener('click', function () { if (step > 0) { step--; showStep(); } });
  next.addEventListener('click', function () { clearErrors(); if (check(questions[step])) { step++; showStep(); } });

  // ---------- timer ----------
  var deadline = parseInt(form.dataset.deadline, 10);
  var timerEl = document.getElementById('px-timer');
  if (deadline && timerEl) {
    var tick = function () {
      var left = Math.max(0, deadline - Math.floor(Date.now() / 1000));
      var h = Math.floor(left / 3600), m = Math.floor(left % 3600 / 60), s = left % 60;
      timerEl.textContent = fa((h ? h + ':' : '') + String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0'));
      timerEl.classList.toggle('low', left < 120);
      if (left <= 0) { clearInterval(iv); showAlert('زمان به پایان رسید. اگر پاسخ‌ها را ثبت نکرده‌اید، ممکن است دیگر پذیرفته نشوند.'); }
    };
    var iv = setInterval(tick, 1000); tick();
  }

  function showAlert(msg) { alertBox.textContent = msg; alertBox.classList.remove('hidden'); alertBox.scrollIntoView({ behavior: 'smooth', block: 'center' }); }

  // ---------- confirm & submit ----------
  var modal = document.getElementById('px-confirm');
  form.addEventListener('submit', function (e) {
    e.preventDefault();
    if (submitting) return;
    clearErrors();
    var firstBad = null;
    questions.forEach(function (q, i) { if (!check(q) && firstBad === null) firstBad = i; });
    if (firstBad !== null) {
      if (stepwise) { step = firstBad; showStep(); }
      questions[firstBad].scrollIntoView({ behavior: 'smooth', block: 'center' });
      return;
    }
    var unanswered = questions.filter(function (q) { return !isAnswered(q); }).length;
    document.getElementById('px-confirm-text').textContent = 'پس از ثبت، امکان تغییر پاسخ‌ها وجود ندارد.' + (unanswered ? ' ' + fa(unanswered) + ' سؤال اختیاری بی‌پاسخ مانده است.' : '');
    modal.classList.remove('hidden');
    document.getElementById('px-confirm-yes').focus();
  });
  document.getElementById('px-confirm-no').addEventListener('click', function () { modal.classList.add('hidden'); });
  document.getElementById('px-confirm-yes').addEventListener('click', function () {
    modal.classList.add('hidden');
    submitting = true; submitBtn.disabled = true; submitBtn.textContent = 'در حال ثبت…';
    fetch(form.dataset.submitUrl, {
      method: 'POST', credentials: 'same-origin', referrerPolicy: 'no-referrer',
      headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf, 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({ answers: collect() })
    }).then(function (r) { return r.json().catch(function () { return {}; }).then(function (d) { return { status: r.status, data: d }; }); })
      .then(function (res) {
        if (res.data.ok) { clearDraft(); window.location.replace(res.data.redirect); return; }
        submitting = false; submitBtn.disabled = false; submitBtn.textContent = 'ثبت پاسخ‌ها';
        if (res.data.error === 'validation' && res.data.errors) {
          var map = { required: 'پاسخ به این سؤال الزامی است.', invalid_option: 'گزینهٔ انتخاب‌شده معتبر نیست.', too_long: 'متن بیش از حد طولانی است.', out_of_range: 'مقدار خارج از بازهٔ مجاز است.', not_a_number: 'یک عدد معتبر وارد کنید.' };
          Object.keys(res.data.errors).forEach(function (qid) {
            var q = form.querySelector('.question[data-qid="' + qid + '"]');
            if (q) setError(q, map[res.data.errors[qid]] || 'پاسخ نامعتبر است.');
          });
          showAlert('لطفاً خطاهای مشخص‌شده را برطرف کنید.');
        } else {
          if (res.data.error === 'already_submitted' || res.data.error === 'closed') clearDraft();
          showAlert(res.data.message || 'ثبت ناموفق بود. دوباره تلاش کنید.');
          if (['closed', 'already_submitted', 'session_expired', 'no_session'].indexOf(res.data.error) >= 0) { submitBtn.disabled = true; }
        }
      })
      .catch(function () {
        submitting = false; submitBtn.disabled = false; submitBtn.textContent = 'ثبت پاسخ‌ها';
        showAlert('ارتباط برقرار نشد. پاسخ‌هایتان روی همین دستگاه محفوظ است؛ دوباره تلاش کنید.');
      });
  });

  restoreDraft();
  if (stepwise) showStep(); else updateProgress();
})();
