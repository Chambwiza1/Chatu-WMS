/* Offline look-up: answers a scan or a search from the stock snapshot saved in this browser. Read-only. */
(function () {
  'use strict';
  const $ = (s) => document.querySelector(s);
  const status = $('#snap-status');
  const out = $('#off-results');
  let snap = null;
  const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
  const num = (n) => (Math.abs(n - Math.round(n)) < 0.0005 ? Math.round(n).toLocaleString() : n.toLocaleString(undefined, { maximumFractionDigits: 3 }));

  function index(s) {
    s.itemById = new Map(s.items.map((i) => [i[0], i]));
    s.binById = new Map(s.bins.map((b) => [b[0], b]));
    s.whById = new Map(s.warehouses.map((w) => [Number(w.id), w]));
    return s;
  }
  function show(s, source) {
    const at = new Date(s.generated_at);
    const age = Math.round((Date.now() - at.getTime()) / 60000);
    status.textContent = (source === 'live' ? 'Up to date: ' : 'Saved list from ') + at.toLocaleString() + (age > 60 && source !== 'live' ? ' (' + Math.round(age / 60) + ' hours old)' : '') +
      ' · ' + s.user.name + ' · ' + s.items.length + ' items, ' + s.stock.length + ' stock lines in ' + s.warehouses.map((w) => w.code).join(', ');
  }

  function stockTable(rows, cols) {
    if (!rows.length) return '<p class="muted">None in stock in your warehouses.</p>';
    const head = { item: 'Item', wh: 'Warehouse', bin: 'Bin', lot: 'Batch', exp: 'Expiry', sn: 'Serial', st: 'Status', qty: 'Quantity' };
    let h = '<div class="table-wrap"><table><thead><tr>' + cols.map((c) => '<th' + (c === 'qty' ? ' class="num"' : '') + '>' + head[c] + '</th>').join('') + '</tr></thead><tbody>';
    rows.forEach((r) => {
      const it = snap.itemById.get(r[0]) || [0, '?', '?', '', ''];
      const b = snap.binById.get(r[2]) || [0, 0, '?'];
      const w = snap.whById.get(r[1]) || { code: '?' };
      const cell = { item: '<span class="tag-plate">' + esc(it[1]) + '</span> ' + esc(it[2]), wh: esc(w.code), bin: '<span class="mono">' + esc(b[2]) + '</span>', lot: esc(r[6]), exp: esc(r[7]), sn: '<span class="mono">' + esc(r[8]) + '</span>',
        st: r[3] === 'available' ? (r[5] > 0 ? '<span class="muted small">' + num(r[5]) + ' reserved</span>' : '') : '<span class="badge warn">' + esc(r[3]) + '</span>', qty: num(r[4]) + ' ' + esc(it[4]) };
      h += '<tr>' + cols.map((c) => '<td' + (c === 'qty' ? ' class="num"' : '') + '>' + cell[c] + '</td>').join('') + '</tr>';
    });
    return h + '</tbody></table></div>';
  }

  function find(q) {
    q = q.trim();
    if (!q) { out.innerHTML = ''; return; }
    if (!snap) { out.innerHTML = '<div class="panel empty">No saved stock list in this browser. Sign in once while online and open any page; the list is saved for offline use.</div>'; return; }
    const Q = q.toUpperCase();
    // exact: item code or barcode, pack barcode
    let item = snap.items.find((i) => i[1] === Q || (i[3] && i[3] === q));
    let pack = null;
    if (!item) {
      for (const i of snap.items) { const p = i[6].find((u) => u[2] && u[2] === q); if (p) { item = i; pack = p; break; } }
    }
    if (item) {
      const rows = snap.stock.filter((s) => s[0] === item[0]);
      const total = rows.reduce((a, s) => a + (s[3] === 'available' ? s[4] : 0), 0);
      out.innerHTML = '<section class="panel"><h2><span class="tag-plate">' + esc(item[1]) + '</span> ' + esc(item[2]) + '</h2>' +
        (pack ? '<p>Scanned a <b>' + esc(pack[0]) + '</b> of ' + num(pack[1]) + ' ' + esc(item[4]) + '.</p>' : '') +
        '<p>' + num(total) + ' ' + esc(item[4]) + ' available' + (item[6].length ? ' · packs: ' + item[6].map((u) => esc(u[0]) + ' = ' + num(u[1])).join(', ') : '') + '</p>' +
        stockTable(rows, ['wh', 'bin', 'lot', 'exp', 'sn', 'st', 'qty']) + '</section>';
      return;
    }
    const bins = snap.bins.filter((b) => b[2] === Q);
    if (bins.length) {
      out.innerHTML = bins.map((b) => '<section class="panel"><h2>Bin <span class="mono">' + esc(b[2]) + '</span> · ' + esc((snap.whById.get(b[1]) || {}).code) + (b[4] !== 'active' ? ' <span class="badge warn">' + esc(b[4]) + '</span>' : '') + '</h2>' +
        stockTable(snap.stock.filter((s) => s[2] === b[0]), ['item', 'lot', 'exp', 'sn', 'st', 'qty']) + '</section>').join('');
      return;
    }
    const byLot = snap.stock.filter((s) => s[6] === Q || s[8] === q || s[8] === Q);
    if (byLot.length) {
      out.innerHTML = '<section class="panel"><h2>' + (byLot[0][8] && (byLot[0][8] === q || byLot[0][8] === Q) ? 'Serial ' : 'Batch ') + esc(q) + '</h2>' + stockTable(byLot, ['item', 'wh', 'bin', 'lot', 'exp', 'sn', 'st', 'qty']) + '</section>';
      return;
    }
    const words = q.toLowerCase().split(/\s+/);
    const hits = snap.items.filter((i) => words.every((w) => (i[1] + ' ' + i[2]).toLowerCase().includes(w))).slice(0, 50);
    if (!hits.length) { out.innerHTML = '<div class="panel empty">Nothing in the saved list matches “' + esc(q) + '”.</div>'; return; }
    out.innerHTML = '<section class="panel"><h2>Items matching “' + esc(q) + '”</h2><div class="table-wrap"><table><thead><tr><th>Item</th><th class="num">Available</th><th>Bins</th></tr></thead><tbody>' +
      hits.map((i) => {
        const rows = snap.stock.filter((s) => s[0] === i[0] && s[3] === 'available');
        const bins = [...new Set(rows.map((s) => (snap.binById.get(s[2]) || [0, 0, '?'])[2]))].slice(0, 6);
        return '<tr><td><button class="linkish" type="button" data-code="' + esc(i[1]) + '"><span class="tag-plate">' + esc(i[1]) + '</span></button> ' + esc(i[2]) + '</td><td class="num">' + num(rows.reduce((a, s) => a + s[4], 0)) + ' ' + esc(i[4]) + '</td><td class="mono">' + bins.map(esc).join(', ') + '</td></tr>';
      }).join('') + '</tbody></table></div></section>';
  }

  out.addEventListener('click', (e) => { const b = e.target.closest('[data-code]'); if (b) { $('#off-q').value = b.dataset.code; find(b.dataset.code); } });
  $('#off-form').addEventListener('submit', (e) => { e.preventDefault(); find($('#off-q').value); $('#off-q').select(); });

  (async () => {
    const S = window.ChatuSnap;
    if (!S) { status.textContent = 'This browser cannot keep an offline list.'; return; }
    try {
      if (navigator.onLine) { snap = index(await S.refresh()); show(snap, 'live'); return; }
    } catch (e) { /* offline or signed out: use the saved list */ }
    try {
      const saved = await S.get();
      if (saved) { snap = index(saved); show(snap, 'saved'); } else { status.textContent = 'No saved stock list yet. Sign in while online first.'; }
    } catch (e) { status.textContent = 'This browser cannot keep an offline list.'; }
  })();
})();
