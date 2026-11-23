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
    const msg = e.submitter && e.submitter.dataset.confirm;
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
