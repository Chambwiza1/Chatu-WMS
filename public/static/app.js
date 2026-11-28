/*
 * Chatu Warehouse page behaviour. Every change is made by the server in one transaction; the page only
 * guards against double submits, works the line editors and reads barcodes with the camera.
 */
(function () {
  'use strict';
  const base = document.body.dataset.base || location.origin;
  let online = navigator.onLine;

  if (!document.cookie.split('; ').some((c) => c.startsWith('device_id='))) {
    const id = (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2));
    document.cookie = 'device_id=' + id + '; path=/; max-age=31536000; SameSite=Lax' + (location.protocol === 'https:' ? '; Secure' : '');
  }

  function setOnline(state) {
    online = state;
    document.body.classList.toggle('is-offline', !state);
    const banner = document.getElementById('net-banner');
    if (banner) banner.hidden = state;
  }
  async function check() {
    const ctrl = new AbortController();
    const t = setTimeout(() => ctrl.abort(), 6000);
    try {
      await fetch(base + '/api/ping', { cache: 'no-store', credentials: 'same-origin', signal: ctrl.signal });
      setOnline(true);
    } catch (e) {
      setOnline(false);
    } finally {
      clearTimeout(t);
    }
  }
  window.addEventListener('online', check);
  window.addEventListener('offline', () => setOnline(false));
  setInterval(check, 60000);

  // a double submit is sent once; confirmations; nothing is sent offline
  document.addEventListener('submit', (e) => {
    const form = e.target;
    if ((form.method || '').toLowerCase() !== 'post') return;
    if (!online) { e.preventDefault(); alert('Not saved: you are offline. Try again when you are connected.'); return; }
    const msg = (e.submitter && e.submitter.dataset.confirm) || form.dataset.confirm;
    if (msg && !confirm(msg)) { e.preventDefault(); return; }
    if (form.dataset.submitted) { e.preventDefault(); return; }
    form.dataset.submitted = '1';
    if (e.submitter && e.submitter.name) {
      const h = document.createElement('input');
      h.type = 'hidden'; h.name = e.submitter.name; h.value = e.submitter.value;
      form.appendChild(h);
    }
    setTimeout(() => form.querySelectorAll('button[type="submit"]').forEach((b) => { b.disabled = true; }), 0);
  });

  document.addEventListener('click', (e) => {
    const p = e.target.closest('[data-print]');
    if (p) { e.preventDefault(); window.print(); }
    // line editors: <table data-lines> with a <template> row; [data-add-line] adds one, [data-remove-line] removes one
    const add = e.target.closest('[data-add-line]');
    if (add) {
      e.preventDefault();
      const table = document.querySelector(add.dataset.addLine);
      const tpl = table.querySelector('template');
      const n = Number(table.dataset.next || table.querySelectorAll('tbody tr').length);
      table.dataset.next = n + 1;
      const row = tpl.content.firstElementChild.cloneNode(true);
      row.innerHTML = row.innerHTML.replace(/__N__/g, String(n));
      table.querySelector('tbody').appendChild(row);
      const first = row.querySelector('input,select');
      if (first) first.focus();
    }
    const rm = e.target.closest('[data-remove-line]');
    if (rm) {
      e.preventDefault();
      const tr = rm.closest('tr');
      if (tr && tr.parentElement.children.length > 1) tr.remove();
      else if (tr) tr.querySelectorAll('input').forEach((i) => { i.value = ''; });
    }
    // fill every quantity box of a group with its maximum
    const fill = e.target.closest('[data-fill]');
    if (fill) {
      e.preventDefault();
      document.querySelectorAll(fill.dataset.fill).forEach((i) => { if (i.dataset.max !== undefined) i.value = i.dataset.max; });
    }
  });

  document.querySelectorAll('[data-autosubmit]').forEach((s) => s.addEventListener('change', () => s.form.submit()));

  // the chosen item decides which fields apply (batch, expiry, serials) and which packs can be entered
  function itemOption(value) {
    const v = (value || '').trim().toUpperCase();
    const code = v.split(' — ')[0];
    return [...document.querySelectorAll('#item-list option')].find((o) => o.value.toUpperCase() === v || o.value.toUpperCase().split(' — ')[0] === code);
  }
  function fillUnits(row, opt, keep) {
    const sel = row.querySelector('select[data-unit]');
    if (!sel) return;
    const want = keep || sel.dataset.selected || sel.value;
    const purpose = sel.dataset.unit; // buy | sell | any
    sel.innerHTML = '';
    if (!opt) { sel.add(new Option('—', '')); return; }
    sel.add(new Option(opt.dataset.uom, ''));
    let def = '';
    (opt.dataset.units || '').split('|').filter(Boolean).forEach((u) => {
      const [name, factor, flags] = u.split(':');
      sel.add(new Option(name + ' (' + factor + ' ' + opt.dataset.uom + ')', name));
      if ((purpose === 'buy' && flags && flags[0] === '1') || (purpose === 'sell' && flags && flags[1] === '1')) def = name;
    });
    sel.value = want && [...sel.options].some((o) => o.value === want) ? want : def;
  }
  function onItem(inp) {
    const row = inp.closest('tr');
    const opt = itemOption(inp.value);
    if (!row) return;
    if (opt) row.dataset.tracking = opt.dataset.tracking || 'none';
    row.querySelectorAll('[data-uom]').forEach((u) => { u.textContent = opt ? opt.dataset.uom : ''; });
    fillUnits(row, opt);
  }
  document.addEventListener('input', (e) => {
    const inp = e.target.closest('input[list="item-list"]');
    if (inp) onItem(inp);
  });
  document.addEventListener('change', (e) => {
    const inp = e.target.closest('input[list="item-list"]');
    if (inp) onItem(inp);
  });
  document.querySelectorAll('input[list="item-list"]').forEach((inp) => { if (inp.value) onItem(inp); });

  // camera scanning (QR, DataMatrix, Code 128)
  document.querySelectorAll('[data-scanner]').forEach((box) => {
    const btn = box.querySelector('[data-scan-start]');
    const video = box.querySelector('video');
    const status = box.querySelector('[data-scan-status]');
    const target = document.querySelector(box.dataset.target);
    const form = document.querySelector(box.dataset.submit);
    let stream = null;
    const stop = () => { if (stream) stream.getTracks().forEach((t) => t.stop()); stream = null; video.hidden = true; };
    const found = (text) => {
      stop();
      target.value = text.replace(/^\(8004\)/, '').trim();
      status.textContent = 'Read ' + target.value;
      target.dispatchEvent(new Event('input', { bubbles: true }));
      if (form) form.requestSubmit ? form.requestSubmit() : form.submit();
    };
    btn.addEventListener('click', async () => {
      if (stream) { stop(); return; }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' }, audio: false });
      } catch (e) {
        status.textContent = 'The camera is not available. Type the tag instead.';
        return;
      }
      video.srcObject = stream;
      video.hidden = false;
      await video.play();
      status.textContent = 'Point the camera at the tag.';
      const detector = 'BarcodeDetector' in window ? new window.BarcodeDetector({ formats: ['qr_code', 'data_matrix', 'code_128'] }) : null;
      const canvas = document.createElement('canvas');
      const ctx = canvas.getContext('2d', { willReadFrequently: true });
      const tick = async () => {
        if (!stream) return;
        try {
          if (detector) {
            const codes = await detector.detect(video);
            if (codes.length) return found(codes[0].rawValue);
          } else if (window.jsQR && video.videoWidth) {
            canvas.width = video.videoWidth; canvas.height = video.videoHeight;
            ctx.drawImage(video, 0, 0);
            const img = ctx.getImageData(0, 0, canvas.width, canvas.height);
            const code = window.jsQR(img.data, img.width, img.height);
            if (code) return found(code.data);
          }
        } catch (e) { /* keep trying */ }
        requestAnimationFrame(tick);
      };
      tick();
    });
  });
  // offline look-up: the browser keeps a read-only stock snapshot (IndexedDB), refreshed while signed in and online
  const Snap = {
    db() {
      return new Promise((ok, fail) => {
        if (!window.indexedDB) return fail(new Error('no IndexedDB'));
        const r = indexedDB.open('chatuwms', 1);
        r.onupgradeneeded = () => r.result.createObjectStore('snap');
        r.onsuccess = () => ok(r.result);
        r.onerror = () => fail(r.error);
      });
    },
    async put(v) { const d = await Snap.db(); return new Promise((ok, fail) => { const t = d.transaction('snap', 'readwrite'); t.objectStore('snap').put(v, 'current'); t.oncomplete = ok; t.onerror = () => fail(t.error); }); },
    async get() { const d = await Snap.db(); return new Promise((ok, fail) => { const r = d.transaction('snap').objectStore('snap').get('current'); r.onsuccess = () => ok(r.result || null); r.onerror = () => fail(r.error); }); },
    async clear() { try { const d = await Snap.db(); d.transaction('snap', 'readwrite').objectStore('snap').clear(); } catch (e) { /* nothing saved */ } },
    async refresh() {
      const res = await fetch(base + '/offline/snapshot', { credentials: 'same-origin', cache: 'no-store', headers: { Accept: 'application/json' } });
      if (!res.ok || !(res.headers.get('Content-Type') || '').includes('json')) throw new Error('not signed in');
      const data = await res.json();
      await Snap.put(data);
      try { localStorage.setItem('chatuwms_snap_at', String(Date.now())); } catch (e) { /* private mode */ }
      return data;
    },
  };
  window.ChatuSnap = Snap;
  document.addEventListener('submit', (e) => { if (/\/logout$/.test(e.target.getAttribute('action') || '')) Snap.clear(); }, true);
  if (document.body.dataset.uid && !document.body.dataset.snapshot) Snap.clear();
  if (document.body.dataset.snapshot) {
    let last = 0;
    try { last = Number(localStorage.getItem('chatuwms_snap_at') || 0); } catch (e) { /* private mode */ }
    if (Date.now() - last > 15 * 60 * 1000) setTimeout(() => Snap.refresh().catch(() => {}), 1500);
  }
  if ('serviceWorker' in navigator && document.body.dataset.base && location.protocol !== 'file:') {
    navigator.serviceWorker.register((document.body.dataset.offlinePage !== undefined ? '' : base + '/') + 'sw.js').catch(() => {});
  }
})();

/* core 1.1: the permissions grid and the message pop-ups (from ChatuPOS 2.0) */
(function () {
  'use strict';
  // permissions grid (core 1.1, as ChatuPOS beta12): "all" ticks a module's row; on a person's page, the boxes a ticked role gives are greyed
  document.addEventListener('click', (e) => {
    const b = e.target.closest('[data-tick-row]');
    if (!b) return;
    const boxes = [...b.closest('tr').querySelectorAll('input[type=checkbox]:not([disabled])')];
    const on = boxes.some((x) => !x.checked);
    boxes.forEach((x) => { x.checked = on; });
  });
  const af = document.querySelector('[data-access-form]');
  if (af) {
    const direct = new Set([...af.querySelectorAll('[data-rights] input[data-code]')].filter((x) => x.checked && !x.dataset.roleGiven).map((x) => x.dataset.code));
    const sync = () => {
      const given = {};
      af.querySelectorAll('input[name="roles[]"]').forEach((r) => {
        if (!r.checked) return;
        (r.dataset.perms || '').split(',').filter(Boolean).forEach((c) => { (given[c] = given[c] || []).push(r.dataset.roleName); });
      });
      const locked = af.querySelector('input[name="roles[]"]') && af.querySelector('input[name="roles[]"]').disabled;
      af.querySelectorAll('[data-rights] input[data-code]').forEach((x) => {
        const g = given[x.dataset.code];
        const label = x.closest('label');
        if (g) {
          if (!x.dataset.roleGiven && x.checked) direct.add(x.dataset.code);
          x.dataset.roleGiven = '1'; x.checked = true; x.disabled = true; label.classList.add('from-role');
          label.title = label.title.replace(/\. From the role .*$/, '') + '. From the role ' + g.join(', ');
        } else if (x.dataset.roleGiven) {
          delete x.dataset.roleGiven; x.disabled = !!locked; x.checked = direct.has(x.dataset.code); label.classList.remove('from-role');
          label.title = label.title.replace(/\. From the role .*$/, '');
        }
      });
    };
    af.addEventListener('change', (e) => {
      const x = e.target;
      if (x.matches('[data-rights] input[data-code]')) { if (x.checked) direct.add(x.dataset.code); else direct.delete(x.dataset.code); }
      if (x.matches('input[name="roles[]"]')) sync();
      if (x.matches('input[name="warehouses[]"]') && x.checked) { const o = af.querySelector('input[name="all_warehouses"][value="0"]'); if (o) o.checked = true; }
    });
  }
  // core 1.1 (E2, as ChatuPOS and Chatu Assets): new messages pop up on every screen with a short tone and stay until
  // each is opened or closed with x (which reads it); Close all hides them until the next sign-in without reading them.
  // Each device can mute the tone. The check every 45 s is not activity.
  (function live() {
    const body = document.body;
    const url = body.dataset.live;
    if (!url || document.querySelector('[data-no-live]')) return;
    const uid = body.dataset.liveUser || '0';
    const K_MSG = 'chatu.live.msg.' + uid;
    const K_ALERT = 'chatu.live.alerts.' + uid;
    const K_MUTE = 'chatu.live.mute';
    const K_HID = 'chatu.live.hidden.' + uid;
    const store = (st, k, v) => { try { if (v === undefined) return st.getItem(k); st.setItem(k, v); } catch (e) { /* private mode */ } return null; };
    let ctx = null;
    const unlock = () => { if (!ctx) { try { ctx = new (window.AudioContext || window.webkitAudioContext)(); } catch (e) { ctx = null; } } };
    document.addEventListener('pointerdown', unlock, { once: true });
    document.addEventListener('keydown', unlock, { once: true });
    function chime(urgent) {
      if (!ctx || store(localStorage, K_MUTE) === '1') return;
      try {
        if (ctx.state === 'suspended') ctx.resume();
        const notes = urgent ? [880, 660, 880] : [660, 880];
        notes.forEach((f, i) => {
          const o = ctx.createOscillator(); const g = ctx.createGain();
          o.type = 'sine'; o.frequency.value = f; o.connect(g); g.connect(ctx.destination);
          const t0 = ctx.currentTime + i * 0.18;
          g.gain.setValueAtTime(0.0001, t0); g.gain.exponentialRampToValueAtTime(0.25, t0 + 0.02); g.gain.exponentialRampToValueAtTime(0.0001, t0 + 0.16);
          o.start(t0); o.stop(t0 + 0.17);
        });
      } catch (e) { /* no sound */ }
    }
    let stack = null;
    function holder() {
      if (stack && document.body.contains(stack)) return stack;
      stack = document.createElement('section');
      stack.className = 'live-stack'; stack.setAttribute('aria-label', 'Alerts and messages'); stack.setAttribute('aria-live', 'polite');
      const bar = document.createElement('div'); bar.className = 'live-bar';
      const mute = document.createElement('button'); mute.type = 'button'; mute.className = 'live-mute';
      const label = () => { mute.textContent = store(localStorage, K_MUTE) === '1' ? 'Sound: off on this device' : 'Sound: on'; mute.setAttribute('aria-pressed', store(localStorage, K_MUTE) === '1' ? 'true' : 'false'); };
      mute.addEventListener('click', () => { store(localStorage, K_MUTE, store(localStorage, K_MUTE) === '1' ? '0' : '1'); label(); });
      label();
      const all = document.createElement('button'); all.type = 'button'; all.className = 'live-close-all'; all.textContent = 'Close all';
      all.addEventListener('click', () => {
        // core 1.1: Close all hides; it does not read. The messages stay unread on Messages and in the count.
        let hid = {};
        try { hid = JSON.parse(store(localStorage, K_HID) || '{}'); } catch (e) { hid = {}; }
        hid.ids = hid.ids || [];
        stack.querySelectorAll('.live-n').forEach((n) => { if (n.dataset.id) hid.ids.push(Number(n.dataset.id)); n.remove(); });
        store(localStorage, K_HID, JSON.stringify(hid));
        const more = stack.querySelector('.live-more'); if (more) more.remove();
        stack.hidden = true;
      });
      bar.append(mute, all);
      stack.appendChild(bar);
      document.body.appendChild(stack);
      return stack;
    }
    function csrf() { const i = document.querySelector('input[name^="csrf"], input[name="' + (window.CSRF_NAME || 'csrf_test_name') + '"]'); return i ? [i.name, i.value] : null; }
    function notice(n) {
      const st = holder(); st.hidden = false;
      if (st.querySelectorAll('.live-n').length >= 8) { const old = st.querySelector('.live-n:not([data-live-key^="msg-"])') || st.querySelector('.live-n'); old.remove(); }
      const el = document.createElement('article');
      el.className = 'live-n ' + (n.tone === 'bad' ? 'bad' : n.kind === 'msg' ? 'msg' : 'warn');
      if (n.key) el.dataset.liveKey = n.key; // not data-key: that is the number pad's
      if (n.id) el.dataset.id = String(n.id);
      el.setAttribute('role', n.tone === 'bad' ? 'alert' : 'status');
      const t = document.createElement('p'); t.className = 'live-t';
      const tag = document.createElement('span'); tag.className = 'live-tag'; tag.textContent = n.kind === 'msg' ? 'Message' : 'Alert';
      t.append(tag, document.createTextNode(' ' + n.title));
      el.appendChild(t);
      if (n.body) { const b = document.createElement('p'); b.className = 'live-b'; b.textContent = n.body.length > 160 ? n.body.slice(0, 157) + '…' : n.body; el.appendChild(b); }
      const acts = document.createElement('div'); acts.className = 'live-acts';
      const open = document.createElement('a'); open.href = n.url; open.className = 'btn small'; open.textContent = 'Open';
      acts.appendChild(open);
      if (n.kind === 'alert' && body.dataset.alertsDismiss) {
        const aside = document.createElement('button'); aside.type = 'button'; aside.className = 'btn small secondary'; aside.textContent = 'Put aside';
        aside.title = 'Hide until it changes';
        aside.addEventListener('click', () => {
          const fd = new FormData(); fd.append('key', n.key); const c = csrf(); if (c) fd.append(c[0], c[1]);
          fetch(body.dataset.alertsDismiss, { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Chatu-Popup': '1', Accept: 'application/json' } }).catch(() => {});
          el.remove();
        });
        acts.appendChild(aside);
      }
      const x = document.createElement('button'); x.type = 'button'; x.className = 'live-x'; x.setAttribute('aria-label', 'Close'); x.textContent = '×';
      x.addEventListener('click', () => {
        if (n.kind === 'msg' && n.id && n.read) { // core 1.1: x means "read, put it away"
          const fd = new FormData(); const c = csrf(); if (c) fd.append(c[0], c[1]);
          fetch(n.read + '/' + n.id + '/read', { method: 'POST', body: fd, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' } }).catch(() => {});
        }
        el.remove(); if (!stack.querySelector('.live-n')) stack.hidden = true;
      });
      acts.appendChild(x);
      el.appendChild(acts);
      st.appendChild(el);
    }
    function counts(d) {
      const set = (sel, n, label) => {
        const a = document.querySelector(sel); if (!a) return;
        let c = a.querySelector('.count');
        if (n > 0) { if (!c) { c = document.createElement('span'); c.className = 'count'; a.appendChild(document.createTextNode(' ')); a.appendChild(c); } c.textContent = n > 99 ? '99+' : String(n); }
        else if (c) c.remove();
        if (label) a.setAttribute('aria-label', label + (n ? ': ' + n : ''));
      };
      set('.bell', d.unread); set('.alerts-bell', d.alertCount, 'Alerts');
    }
    let busy = false;
    function poll() {
      if (busy) return; busy = true;
      const seenMsg = Number(store(localStorage, K_MSG) || '-1');
      fetch(url + '?after=' + Math.max(0, seenMsg), { credentials: 'same-origin', headers: { Accept: 'application/json' }, cache: 'no-store' })
        .then((r) => { if (r.status === 401) { clearInterval(timer); return null; } return r.ok ? r.json() : null; })
        .then((d) => {
          busy = false;
          if (!d || !d.ok) return;
          counts(d);
          let fresh = 0; let urgent = false;
          if (d.popups) {
            // core 1.1 (from Chatu Assets 2.2.2): the newest unread messages stay up on every page until each one is
            // opened (reads that one) or closed with x (reads it, puts it away). Close all hides them until the next
            // sign-in without reading them. A message read elsewhere leaves the screen at the next check.
            let hid = {};
            try { hid = JSON.parse(store(localStorage, K_HID) || '{}'); } catch (e) { hid = {}; }
            if (hid.session !== d.session) { hid = { session: d.session, ids: [] }; store(localStorage, K_HID, JSON.stringify(hid)); }
            const want = new Set();
            d.messages.forEach((m) => {
              if (hid.ids.indexOf(m.id) !== -1) return;
              want.add('msg-' + m.id);
              if (!document.querySelector('.live-n[data-live-key="msg-' + m.id + '"]')) {
                notice({ kind: 'msg', key: 'msg-' + m.id, id: m.id, title: m.title, body: m.body, url: m.url, read: d.read });
                if (m.new && seenMsg >= 0) fresh++;
              }
            });
            document.querySelectorAll('.live-n[data-live-key^="msg-"]').forEach((x) => { if (!want.has(x.dataset.liveKey)) x.remove(); });
            let more = stack ? stack.querySelector('.live-more') : null;
            if (d.more > 0 && want.size) {
              holder();
              if (!more) { more = document.createElement('a'); more.className = 'live-more'; stack.appendChild(more); }
              more.href = d.all; more.textContent = d.more + ' more unread on Messages';
              stack.appendChild(more);
            } else if (more) { more.remove(); }
            if (stack && !stack.querySelector('.live-n')) stack.hidden = true;
          }
          // core 1.3 (E1): what waits for this person pops up once per sign-in, and again when its number changes;
          // "Put aside" hides it on the server until then. Its own setting (Alerts pop up), apart from messages.
          let shown = {};
          try { shown = JSON.parse(store(sessionStorage, K_ALERT) || '{}'); } catch (e) { shown = {}; }
          const now = {};
          (d.alerts || []).forEach((a) => {
            now[a.key] = 1;
            if (!shown[a.key]) { notice({ kind: 'alert', key: a.key, title: a.title, body: a.body, tone: a.tone, url: a.url }); fresh++; if (a.tone === 'bad') urgent = true; }
          });
          document.querySelectorAll('.live-n[data-live-key^="a-"]').forEach((x) => { if (!now[x.dataset.liveKey]) x.remove(); });
          if (stack && !stack.querySelector('.live-n')) stack.hidden = true;
          store(sessionStorage, K_ALERT, JSON.stringify(now));
          store(localStorage, K_MSG, String(d.maxId));
          if (fresh && d.sound) chime(urgent);
          if (d.every && d.every * 1000 !== every) { every = d.every * 1000; clearInterval(timer); timer = setInterval(tick, every); }
        })
        .catch(() => { busy = false; });
    }
    setTimeout(poll, 1200);
    let every = 45000;
    const tick = () => { if (!document.hidden || Math.random() < 0.5) poll(); };
    let timer = setInterval(tick, every);
  })();
})();

/* Chatu Warehouse 0.4.0 (core 1.3): light or dark (C1, as ChatuPass), the organisation's colours on swatches (C2),
   and the grouped menu that folds, with menu search and a Menu button on phones (C6). */
(function () {
  'use strict';
  // the Content-Security-Policy allows no inline style attributes; styles set through the CSSOM are fine
  document.querySelectorAll('[data-css]').forEach((el) => { el.style.cssText += ';' + el.dataset.css; });

  // ----- the top-bar button: this device goes account's choice -> Light -> Dark -> back to the account's choice
  const btn = document.querySelector('[data-theme-toggle]');
  const LABEL = { auto: 'Auto', light: 'Light', dark: 'Dark' };
  function paint() {
    if (!btn || !window.ChatuTheme) return;
    const from = window.ChatuTheme.from(); const c = window.ChatuTheme.choice();
    btn.querySelector('.tl').textContent = LABEL[c] || 'Auto';
    btn.setAttribute('aria-label', 'Light or dark: ' + (LABEL[c] || 'Auto') + (from === 'device' ? ', on this device only' : ', from your account') + '. Press to change on this device.');
    btn.setAttribute('title', from === 'device' ? 'On this device only' : 'From your account');
  }
  if (btn && window.ChatuTheme) {
    btn.addEventListener('click', () => {
      const from = window.ChatuTheme.from(); const c = window.ChatuTheme.choice();
      window.ChatuTheme.setDevice(from !== 'device' ? 'light' : (c === 'light' ? 'dark' : ''));
      paint();
      const clr = document.querySelector('[data-theme-device-clear]'); if (clr) clr.hidden = window.ChatuTheme.from() !== 'device';
    });
    paint();
  }
  const sel = document.querySelector('select[data-theme-account]');
  if (sel && window.ChatuTheme) sel.addEventListener('change', () => { window.ChatuTheme.setAccount(sel.value); paint(); });
  const clear = document.querySelector('[data-theme-device-clear]');
  if (clear && window.ChatuTheme) {
    clear.addEventListener('click', () => { window.ChatuTheme.setDevice(''); paint(); clear.hidden = true; });
    clear.hidden = window.ChatuTheme.from() !== 'device';
  }

  // ----- the menu: groups fold; the ones you opened stay open from page to page; the group you are in is always open
  const nav = document.getElementById('sidenav');
  if (!nav) return;
  const K_MENU = 'chatu.menu.open';
  let keep = [];
  try { keep = JSON.parse(localStorage.getItem(K_MENU) || '[]'); } catch (e) { keep = []; }
  const groups = [...nav.querySelectorAll('details.grp')];
  let finding = false;
  groups.forEach((d) => {
    if (keep.includes(d.dataset.grp)) d.open = true;
    d.addEventListener('toggle', () => {
      if (finding) return;
      const open = groups.filter((x) => x.open && !x.hasAttribute('data-here')).map((x) => x.dataset.grp);
      try { localStorage.setItem(K_MENU, JSON.stringify(open)); } catch (e) { /* private mode */ }
    });
  });
  const mbtn = document.querySelector('.menu-btn');
  if (mbtn) {
    mbtn.addEventListener('click', () => {
      const open = nav.classList.toggle('open');
      mbtn.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) { const q = nav.querySelector('[data-menu-find]'); if (q && window.matchMedia('(min-width: 821px)').matches) q.focus(); }
    });
  }
  // menu search: words in any order, part of a word; the group's name counts too. Enter opens the first one shown.
  const q = nav.querySelector('[data-menu-find]');
  const none = nav.querySelector('.menu-none');
  if (q) {
    const was = new Map(groups.map((d) => [d, d.open]));
    const norm = (t) => t.toLowerCase().replace(/\s+/g, ' ').trim();
    const run = () => {
      const words = norm(q.value).split(' ').filter(Boolean);
      finding = true;
      let shown = 0;
      nav.querySelectorAll('a').forEach((a) => {
        const grp = a.closest('details.grp');
        const hay = norm(a.textContent + ' ' + (grp ? grp.dataset.grp : ''));
        const hit = !words.length || words.every((w) => hay.includes(w));
        a.hidden = !hit;
        a.classList.toggle('found', !!words.length && hit);
        if (hit) shown++;
      });
      groups.forEach((d) => {
        const any = [...d.querySelectorAll('a')].some((a) => !a.hidden);
        d.hidden = !!words.length && !any;
        d.open = words.length ? any : was.get(d);
      });
      if (none) none.hidden = !words.length || shown > 0;
      finding = false;
    };
    q.addEventListener('focus', () => { if (!q.value) groups.forEach((d) => was.set(d, d.open)); });
    q.addEventListener('input', run);
    q.addEventListener('keydown', (e) => {
      if (e.key === 'Enter') { const a = [...nav.querySelectorAll('a')].find((x) => !x.hidden && x.classList.contains('found')); if (a) { e.preventDefault(); location.href = a.href; } }
      if (e.key === 'Escape') { q.value = ''; run(); }
    });
  }
})();

/* Chatu Warehouse 0.6.0 (core 1.6, C7, C8; Chatu Construction's top bar): search with suggestions as you type; arrows and
   Enter to choose; "/" to jump to the box; Enter with nothing chosen opens an exact code's page, else the results. */
(function () {
  'use strict';
  document.querySelectorAll('form[data-search]').forEach((form) => {
    const input = form.querySelector('input[type=search]');
    const list = form.querySelector('.suggest');
    let timer = null; let seq = 0; let pos = -1;
    const close = () => { list.hidden = true; input.setAttribute('aria-expanded', 'false'); pos = -1; };
    const links = () => [...list.querySelectorAll('a')];
    const mark = () => links().forEach((a, i) => a.classList.toggle('on', i === pos));
    input.addEventListener('input', () => {
      clearTimeout(timer);
      const q = input.value.trim();
      if (q.length < 2) { close(); return; }
      timer = setTimeout(async () => {
        const my = ++seq;
        try {
          const r = await fetch(document.body.dataset.base + '/search?format=json&q=' + encodeURIComponent(q), { headers: { Accept: 'application/json' } });
          if (!r.ok || my !== seq) return;
          const d = await r.json();
          list.innerHTML = '';
          if (!d.groups.length) {
            const p = document.createElement('p'); p.className = 'muted'; p.textContent = 'Nothing found'; list.appendChild(p);
          }
          d.groups.forEach((g) => {
            const h = document.createElement('div'); h.className = 'g'; h.textContent = g.group; list.appendChild(h);
            g.rows.forEach((row) => {
              const a = document.createElement('a'); a.href = row.href; a.setAttribute('role', 'option');
              const t = document.createElement('span'); t.textContent = row.title; a.appendChild(t);
              if (row.sub) { const s = document.createElement('small'); s.textContent = row.sub; a.appendChild(s); }
              list.appendChild(a);
            });
          });
          const all = document.createElement('a'); all.href = document.body.dataset.base + '/search?q=' + encodeURIComponent(q); all.className = 'all'; all.textContent = 'All results for “' + q + '”';
          list.appendChild(all);
          list.hidden = false; input.setAttribute('aria-expanded', 'true'); pos = -1;
        } catch (e) { /* offline: Enter still opens the results page */ }
      }, 180);
    });
    input.addEventListener('keydown', (e) => {
      const ls = links();
      if (list.hidden || !ls.length) return;
      if (e.key === 'ArrowDown') { pos = Math.min(ls.length - 1, pos + 1); mark(); e.preventDefault(); }
      if (e.key === 'ArrowUp') { pos = Math.max(-1, pos - 1); mark(); e.preventDefault(); }
      if (e.key === 'Enter' && pos >= 0) { e.preventDefault(); ls[pos].click(); }
      if (e.key === 'Escape') close();
    });
    document.addEventListener('click', (e) => { if (!form.contains(e.target)) close(); });
    document.addEventListener('keydown', (e) => {
      if (e.key === '/' && !['INPUT', 'TEXTAREA', 'SELECT'].includes((document.activeElement || {}).tagName)) { e.preventDefault(); input.focus(); }
    });
  });
  document.addEventListener('click', (e) => {
    document.querySelectorAll('details.quickadd[open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; });
  });
})();
