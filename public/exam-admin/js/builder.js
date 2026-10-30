/* Exam builder: tabs, question editor with drag & drop, bulk import, preview, save via JSON API. Vanilla JS. */
(function () {
  'use strict';

  var cfg = JSON.parse(document.getElementById('builder-config').textContent);
  var root = document.getElementById('builder');
  var listEl = document.getElementById('questions');
  var alertEl = document.getElementById('builder-alert');
  var saveBtn = document.getElementById('save-btn');
  var saveState = document.getElementById('save-state');

  var TYPES = {
    single: 'تک‌گزینه‌ای', multi: 'چندگزینه‌ای', true_false: 'درست / نادرست',
    short_text: 'متن کوتاه', long_text: 'متن بلند', rating: 'امتیازدهی', number: 'عدد'
  };
  var CHOICE = ['single', 'multi', 'true_false'];

  var state = {
    id: cfg.examId, locked: false, status: 'draft', public_code: null,
    title: '', description: '', group_id: '', mode: 'survey', starts_at: '', ends_at: '', duration_minutes: '',
    shuffle_questions: false, shuffle_options: false, display_mode: 'single_page', show_result_to_voter: 'none',
    results_visibility: 'after_close', min_responses_to_show: cfg.defaults.min, announce_on_open: true,
    announce_on_close: true, reminders: cfg.defaults.reminders, questions: []
  };
  var dirty = false;

  // ---------- helpers ----------
  function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function el(tag, attrs, children) {
    var e = document.createElement(tag);
    Object.keys(attrs || {}).forEach(function (k) {
      if (k === 'text') e.textContent = attrs[k];
      else if (k === 'class') e.className = attrs[k];
      else if (k.indexOf('on') === 0) e.addEventListener(k.slice(2), attrs[k]);
      else if (attrs[k] === true) e.setAttribute(k, '');
      else if (attrs[k] !== false && attrs[k] != null) e.setAttribute(k, attrs[k]);
    });
    (children || []).forEach(function (c) { if (c) e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); });
    return e;
  }
  function markDirty() { dirty = true; saveState.textContent = 'تغییرات ذخیره نشده'; }
  function showAlert(type, html) {
    alertEl.className = 'alert alert-' + type;
    alertEl.textContent = '';
    if (Array.isArray(html)) {
      var ul = el('ul');
      html.forEach(function (m) { ul.appendChild(el('li', { text: m })); });
      alertEl.appendChild(ul);
    } else { alertEl.textContent = html; }
    alertEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
  }
  function hideAlert() { alertEl.className = 'alert hidden'; }
  function blankQuestion(type) {
    var q = { type: type, text: '', help_text: '', is_required: true, points: 1, config: {}, options: [] };
    if (type === 'single' || type === 'multi') q.options = [{ text: '', is_correct: false }, { text: '', is_correct: false }];
    if (type === 'true_false') q.options = [{ text: 'درست', is_correct: true }, { text: 'نادرست', is_correct: false }];
    if (type === 'rating') q.config = { min: 1, max: 5 };
    return q;
  }

  // ---------- tabs ----------
  root.querySelectorAll('.tabs button').forEach(function (b) {
    b.addEventListener('click', function () {
      root.querySelectorAll('.tabs button').forEach(function (x) { x.classList.toggle('active', x === b); });
      root.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.toggle('active', p.dataset.panel === b.dataset.tab); });
      if (b.dataset.tab === 'preview') renderPreview();
    });
  });

  // ---------- basic fields ----------
  var groupSelect = document.getElementById('f-group');
  cfg.groups.forEach(function (g) { groupSelect.appendChild(el('option', { value: g.id, text: g.title })); });

  function fieldEls() { return root.querySelectorAll('[data-field]'); }
  function fillFields() {
    fieldEls().forEach(function (f) {
      var v = state[f.dataset.field];
      if (f.type === 'checkbox') f.checked = !!v; else f.value = v == null ? '' : v;
    });
  }
  fieldEls().forEach(function (f) {
    f.addEventListener(f.type === 'checkbox' || f.tagName === 'SELECT' ? 'change' : 'input', function () {
      state[f.dataset.field] = f.type === 'checkbox' ? f.checked : f.value;
      if (f.dataset.field === 'mode') renderQuestions();
      markDirty();
    });
  });

  function applyLock() {
    document.getElementById('lock-note').classList.toggle('hidden', !state.locked);
    fieldEls().forEach(function (f) { f.disabled = state.locked && !f.hasAttribute('data-safe'); });
    document.getElementById('q-toolbar').classList.toggle('hidden', state.locked);
    var run = document.getElementById('run-link');
    if (state.id && cfg.showUrl) { run.href = cfg.showUrl; run.classList.remove('hidden'); }
  }

  // ---------- questions ----------
  var dragIndex = null;

  function renderQuestions() {
    listEl.textContent = '';
    document.getElementById('q-empty').classList.toggle('hidden', state.questions.length > 0);
    document.getElementById('q-count').textContent = fa(state.questions.length);
    state.questions.forEach(function (q, i) { listEl.appendChild(questionCard(q, i)); });
  }

  function questionCard(q, i) {
    var locked = state.locked;
    var card = el('div', { class: 'q-card', 'data-index': i, draggable: locked ? 'false' : 'true' });

    var typeSel = el('select', { 'aria-label': 'نوع سؤال', disabled: locked });
    Object.keys(TYPES).forEach(function (t) { typeSel.appendChild(el('option', { value: t, text: TYPES[t] })); });
    typeSel.value = q.type;
    typeSel.addEventListener('change', function () {
      var fresh = blankQuestion(typeSel.value);
      fresh.text = q.text; fresh.help_text = q.help_text; fresh.is_required = q.is_required; fresh.points = q.points;
      if (CHOICE.indexOf(typeSel.value) >= 0 && typeSel.value !== 'true_false' && q.options.length) {
        fresh.options = q.options.map(function (o) { return { text: o.text, is_correct: typeSel.value === 'multi' ? o.is_correct : false }; });
      }
      state.questions[i] = fresh; markDirty(); renderQuestions();
    });

    var head = el('div', { class: 'q-head' }, [
      el('span', { class: 'handle', title: 'برای جابه‌جایی بکشید', text: '⋮⋮' }),
      el('span', { class: 'num', text: fa(i + 1) }),
      typeSel,
      el('span', { class: 'spacer' }),
      locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-ghost', title: 'بالا', text: '↑', onclick: function () { move(i, i - 1); } }),
      locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-ghost', title: 'پایین', text: '↓', onclick: function () { move(i, i + 1); } }),
      locked ? null : el('button', { type: 'button', class: 'btn btn-sm', text: 'تکثیر', onclick: function () {
        state.questions.splice(i + 1, 0, JSON.parse(JSON.stringify(q))); markDirty(); renderQuestions();
      } }),
      locked ? null : el('button', { type: 'button', class: 'btn btn-sm btn-ghost', text: 'حذف', onclick: function () {
        if (confirm('این سؤال حذف شود؟')) { state.questions.splice(i, 1); markDirty(); renderQuestions(); }
      } })
    ]);

    var body = el('div', { class: 'q-body' });
    body.appendChild(textInput('متن سؤال *', q.text, function (v) { q.text = v; }, true, locked));
    body.appendChild(textInput('توضیح کمکی (اختیاری)', q.help_text || '', function (v) { q.help_text = v; }, false, locked));

    if (CHOICE.indexOf(q.type) >= 0) body.appendChild(optionsEditor(q, locked));
    if (q.type === 'short_text' || q.type === 'long_text') {
      body.appendChild(numberInput('حداکثر طول (کاراکتر)', (q.config || {}).max_length || '', function (v) { q.config = q.config || {}; q.config.max_length = v; }, locked));
      body.appendChild(el('div', { class: 'hint small muted', text: 'به شرکت‌کننده هشدار داده می‌شود متن را طوری ننویسد که هویتش لو برود.' }));
    }
    if (q.type === 'rating' || q.type === 'number') {
      var r = el('div', { class: 'row' });
      r.appendChild(numberInput('حداقل', (q.config || {}).min != null ? q.config.min : '', function (v) { q.config = q.config || {}; q.config.min = v; }, locked));
      r.appendChild(numberInput('حداکثر', (q.config || {}).max != null ? q.config.max : '', function (v) { q.config = q.config || {}; q.config.max = v; }, locked));
      body.appendChild(r);
    }

    var flags = el('div', { class: 'row' });
    var req = el('label', { class: 'check' }, [el('input', { type: 'checkbox', disabled: locked }), ' پاسخ الزامی']);
    req.querySelector('input').checked = !!q.is_required;
    req.querySelector('input').addEventListener('change', function (e) { q.is_required = e.target.checked; markDirty(); });
    flags.appendChild(req);
    if (state.mode === 'quiz' && CHOICE.indexOf(q.type) >= 0) {
      flags.appendChild(numberInput('نمره', q.points, function (v) { q.points = v; }, locked));
    }
    body.appendChild(flags);

    card.appendChild(head);
    card.appendChild(body);

    if (!locked) {
      card.addEventListener('dragstart', function (e) {
        if (!e.target.classList || !e.target.classList.contains('q-card')) return;
        dragIndex = i; card.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move';
        e.dataTransfer.setData('text/plain', String(i));
      });
      card.addEventListener('dragend', function () { card.classList.remove('dragging'); clearDrop(); });
      card.addEventListener('dragover', function (e) { if (dragIndex === null) return; e.preventDefault(); clearDrop(); card.classList.add('drop-before'); });
      card.addEventListener('drop', function (e) {
        e.preventDefault();
        if (dragIndex !== null && dragIndex !== i) move(dragIndex, dragIndex < i ? i - 1 : i, true);
        dragIndex = null; clearDrop();
      });
      // only start dragging from the handle, so text inputs remain selectable
      card.querySelectorAll('input, textarea, select').forEach(function (inp) {
        inp.addEventListener('mousedown', function () { card.setAttribute('draggable', 'false'); });
        inp.addEventListener('mouseup', function () { card.setAttribute('draggable', 'true'); });
        inp.addEventListener('blur', function () { card.setAttribute('draggable', 'true'); });
      });
    }
    return card;
  }

  function clearDrop() { listEl.querySelectorAll('.drop-before').forEach(function (c) { c.classList.remove('drop-before'); }); }

  function move(from, to, exact) {
    if (to < 0 || to >= state.questions.length || from === to) { if (!exact) return; }
    var item = state.questions.splice(from, 1)[0];
    state.questions.splice(Math.max(0, Math.min(to, state.questions.length)), 0, item);
    markDirty(); renderQuestions();
  }

  function textInput(label, value, onChange, multiline, disabled) {
    var input = multiline ? el('textarea', { rows: 2, maxlength: 2000, disabled: disabled }) : el('input', { type: 'text', maxlength: 1000, disabled: disabled });
    input.value = value || '';
    input.addEventListener('input', function () { onChange(input.value); markDirty(); });
    return el('div', { class: 'field' }, [el('label', { text: label }), input]);
  }
  function numberInput(label, value, onChange, disabled) {
    var input = el('input', { type: 'number', step: 'any', disabled: disabled });
    input.value = value === null || value === undefined ? '' : value;
    input.addEventListener('input', function () { onChange(input.value === '' ? null : Number(input.value)); markDirty(); });
    return el('div', { class: 'field' }, [el('label', { text: label }), input]);
  }

  function optionsEditor(q, locked) {
    var wrap = el('div', { class: 'field' }, [el('span', { class: 'label-text', text: 'گزینه‌ها' })]);
    var quiz = state.mode === 'quiz';
    q.options.forEach(function (o, oi) {
      var txt = el('input', { type: 'text', maxlength: 1000, placeholder: 'متن گزینه', disabled: locked || q.type === 'true_false' && false });
      txt.value = o.text;
      txt.addEventListener('input', function () { o.text = txt.value; markDirty(); });
      var row = el('div', { class: 'opt-row' }, [txt]);
      if (quiz) {
        var mark = el('input', { type: q.type === 'multi' ? 'checkbox' : 'radio', name: 'correct-' + Math.random().toString(36).slice(2), disabled: locked });
        mark.checked = !!o.is_correct;
        mark.addEventListener('change', function () {
          if (q.type !== 'multi') q.options.forEach(function (x) { x.is_correct = false; });
          o.is_correct = mark.checked; markDirty(); renderQuestions();
        });
        row.appendChild(el('label', { class: 'correct' }, [mark, 'صحیح']));
      }
      if (!locked && q.type !== 'true_false') {
        row.appendChild(el('button', { type: 'button', class: 'btn btn-sm btn-ghost', text: '✕', title: 'حذف گزینه', onclick: function () {
          q.options.splice(oi, 1); markDirty(); renderQuestions();
        } }));
      }
      wrap.appendChild(row);
    });
    if (!locked && q.type !== 'true_false') {
      wrap.appendChild(el('button', { type: 'button', class: 'btn btn-sm', text: '+ گزینه', onclick: function () {
        q.options.push({ text: '', is_correct: false }); markDirty(); renderQuestions();
      } }));
    }
    return wrap;
  }

  document.getElementById('add-q').addEventListener('click', function () {
    state.questions.push(blankQuestion(document.getElementById('new-q-type').value));
    markDirty(); renderQuestions();
    var last = listEl.lastElementChild; if (last) { last.scrollIntoView({ behavior: 'smooth' }); var t = last.querySelector('textarea'); if (t) t.focus(); }
  });

  // ---------- bulk import ----------
  document.getElementById('toggle-import').addEventListener('click', function () {
    document.getElementById('import-box').classList.toggle('hidden');
  });
  document.getElementById('run-import').addEventListener('click', function () {
    var raw = document.getElementById('import-raw').value;
    if (!raw.trim()) return;
    window.axApi('POST', cfg.importUrl, { raw: raw, format: document.getElementById('import-format').value }).then(function (res) {
      var qs = (res.data && res.data.questions) || [];
      if (!qs.length) { showAlert('warn', 'هیچ سؤالی شناسایی نشد. قالب متن را بررسی کنید.'); return; }
      qs.forEach(function (q) { if (Array.isArray(q.config)) q.config = {}; state.questions.push(q); });
      document.getElementById('import-raw').value = '';
      document.getElementById('import-box').classList.add('hidden');
      showAlert('success', fa(qs.length) + ' سؤال اضافه شد. برای ثبت، «ذخیره» را بزنید.');
      markDirty(); renderQuestions();
    });
  });

  // ---------- preview ----------
  function renderPreview() {
    var area = document.getElementById('preview-area');
    area.textContent = '';
    area.appendChild(el('div', { class: 'anon-note', text: '🔒 پاسخ‌های شما ناشناس ثبت می‌شود.' }));
    area.appendChild(el('h2', { text: state.title || '(بدون عنوان)' }));
    if (state.description) area.appendChild(el('p', { class: 'muted', text: state.description }));
    state.questions.forEach(function (q, i) {
      var box = el('div', { class: 'question' }, [
        el('div', { class: 'q-title' }, [fa(i + 1) + '. ' + (q.text || '(بدون متن)'), q.is_required ? el('span', { class: 'req', text: ' *' }) : null])
      ]);
      if (q.help_text) box.appendChild(el('div', { class: 'q-help', text: q.help_text }));
      if (CHOICE.indexOf(q.type) >= 0) {
        q.options.forEach(function (o) {
          box.appendChild(el('label', { class: 'choice' }, [el('input', { type: q.type === 'multi' ? 'checkbox' : 'radio', disabled: true }), el('span', { text: o.text + (state.mode === 'quiz' && o.is_correct ? '  ✓' : '') })]));
        });
      } else if (q.type === 'rating') {
        var r = el('div', { class: 'rating' });
        for (var v = (q.config.min || 1); v <= (q.config.max || 5); v++) r.appendChild(el('label', { text: fa(v) }));
        box.appendChild(r);
      } else {
        box.appendChild(el(q.type === 'long_text' ? 'textarea' : 'input', { type: 'text', disabled: true }));
      }
      area.appendChild(box);
    });
    if (!state.questions.length) area.appendChild(el('div', { class: 'empty', text: 'سؤالی برای پیش‌نمایش وجود ندارد.' }));
  }

  // ---------- validation & save ----------
  function clientProblems() {
    var p = [];
    if (!state.title.trim()) p.push('عنوان آزمون را وارد کنید.');
    if (state.starts_at && state.ends_at && state.starts_at >= state.ends_at) p.push('زمان پایان باید بعد از زمان شروع باشد.');
    state.questions.forEach(function (q, i) {
      if (!q.text.trim()) p.push('سؤال ' + fa(i + 1) + ' متن ندارد.');
      if (CHOICE.indexOf(q.type) >= 0) {
        if (q.options.filter(function (o) { return o.text.trim(); }).length < 2) p.push('سؤال ' + fa(i + 1) + ' باید دست‌کم دو گزینه داشته باشد.');
        if (q.options.some(function (o) { return !o.text.trim(); })) p.push('سؤال ' + fa(i + 1) + ' گزینهٔ خالی دارد.');
      }
    });
    return p;
  }

  function payload() {
    var out = {};
    ['title', 'description', 'mode', 'starts_at', 'ends_at', 'display_mode', 'show_result_to_voter', 'results_visibility', 'reminders'].forEach(function (k) { out[k] = state[k] === '' ? null : state[k]; });
    out.group_id = state.group_id ? Number(state.group_id) : null;
    out.duration_minutes = state.duration_minutes ? Number(state.duration_minutes) : null;
    out.min_responses_to_show = Number(state.min_responses_to_show) || 5;
    ['shuffle_questions', 'shuffle_options', 'announce_on_open', 'announce_on_close'].forEach(function (k) { out[k] = !!state[k]; });
    out.title = state.title.trim();
    out.questions = state.questions.map(function (q) {
      return {
        type: q.type, text: q.text.trim(), help_text: q.help_text ? q.help_text.trim() : null, is_required: !!q.is_required,
        points: q.points === null || q.points === '' ? 1 : Number(q.points), config: q.config || {},
        options: (q.options || []).map(function (o) { return { text: o.text.trim(), is_correct: !!o.is_correct }; })
      };
    });
    return out;
  }

  saveBtn.addEventListener('click', function () {
    hideAlert();
    var problems = state.locked ? [] : clientProblems();
    if (problems.length) { showAlert('error', problems); return; }
    saveBtn.disabled = true; saveState.textContent = 'در حال ذخیره…';
    var url = state.id ? cfg.updateUrl : cfg.storeUrl;
    window.axApi(state.id ? 'PUT' : 'POST', url, payload()).then(function (res) {
      saveBtn.disabled = false;
      if (res.ok && res.data.ok) {
        dirty = false;
        if (!state.id && res.data.edit_url) { window.location = res.data.edit_url; return; }
        load(res.data.exam);
        saveState.textContent = 'ذخیره شد ✓';
        showAlert('success', 'آزمون ذخیره شد.');
      } else if (res.status === 422 && res.data.errors) {
        var msgs = []; Object.keys(res.data.errors).forEach(function (k) { msgs = msgs.concat(res.data.errors[k]); });
        showAlert('error', msgs); saveState.textContent = '';
      } else {
        showAlert('error', (res.data && res.data.message) || 'ذخیره ناموفق بود.'); saveState.textContent = '';
      }
    });
  });

  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  function load(exam) {
    Object.keys(exam).forEach(function (k) { state[k] = exam[k]; });
    state.group_id = exam.group_id || '';
    state.description = exam.description || '';
    state.duration_minutes = exam.duration_minutes || '';
    state.questions = (exam.questions || []).map(function (q) {
      q.config = Array.isArray(q.config) || !q.config ? {} : q.config;
      q.options = q.options || [];
      return q;
    });
    fillFields(); applyLock(); renderQuestions();
  }

  if (cfg.loadUrl) {
    window.axApi('GET', cfg.loadUrl).then(function (res) { if (res.ok) load(res.data); else showAlert('error', 'بارگذاری آزمون ناموفق بود.'); });
  } else {
    fillFields(); applyLock(); renderQuestions();
  }
})();
