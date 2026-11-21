(function () {
  const URL = '/plugins/mover.dashboard/include/status.php';
  const POLL_MS = 2000;
  // Full scale of a drive's read and write strips.
  const HDD_MAX = 250e6, SSD_MAX = 2000e6;
  const $ = (id) => document.getElementById(id);
  const truck = '<svg><use href="#mm-truck"/></svg>';

  const size = (b) => {
    if (b == null) return '—';
    const u = ['B', 'KB', 'MB', 'GB', 'TB'];
    let i = 0;
    while (b >= 1000 && i < u.length - 1) { b /= 1000; i++; }
    return (i ? b.toFixed(b < 10 ? 2 : b < 100 ? 1 : 0) : b) + ' ' + u[i];
  };
  const mbs = (b) => (b == null ? '—' : (b / 1e6).toFixed(0));
  const rate = (b) => (b == null ? '—' : mbs(b) + ' MB/s');
  const dur = (s) => {
    if (!s || s < 0 || !isFinite(s)) return '—';
    const d = Math.floor(s / 86400), h = Math.floor((s % 86400) / 3600), m = Math.floor((s % 3600) / 60), sec = Math.floor(s % 60);
    return d ? `${d}d ${h}h` : h ? `${h}h ${String(m).padStart(2, '0')}m` : m ? `${m}m ${sec}s` : `${sec}s`;
  };
  const esc = (s) => String(s ?? '').replace(/[&<>"]/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]));
  const text = (v) => String(v && v.rel !== undefined ? v.rel : v ?? '');
  const base = (rel) => text(rel).split('/').pop();
  // "Movies/M/Mortal Kombat II (2026) {imdb-...}/Mortal Kombat II (2026).mkv" -> "Mortal Kombat II (2026)"
  const title = (rel) => base(rel).replace(/\.partial$/, '').replace(/\.[^.]{2,4}$/, '');
  const app = (by) => {
    if (by && by.mover) return `<span class="mm-app mm-mover" title="mover">${truck}</span>`;
    if (by && by.icon) return `<span class="mm-app" title="${esc(by.name)}"><img src="${esc(by.icon)}" alt=""></span>`;
    return '<span></span>';
  };

  function strip(d) {
    const st = $('mm-state');
    st.textContent = d.running ? 'Moving' : 'Idle';
    st.className = 'mm-chip ' + (d.running ? 'mm-on' : 'mm-plain');
    const tb = $('mm-turbo');
    tb.hidden = d.turbo == null;
    tb.textContent = d.turbo ? 'Turbo write on' : 'Turbo write off';
    $('mm-since').textContent = d.running && d.started
      ? 'since ' + new Date(d.started * 1000).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' }) : '';

    const waitBytes = d.waiting.reduce((a, w) => a + w.bytes, 0);
    const waitFiles = d.waiting.reduce((a, w) => a + w.files, 0);
    $('mm-f-wait').textContent = size(waitBytes);
    $('mm-f-wait-s').textContent = `waiting on cache · ${waitFiles} files`;
    const p = d.progress;
    const avg = recentAverage(d.history);
    if (p && p.total) {
      $('mm-f-eta').textContent = '≈ ' + dur(p.eta);
      $('mm-f-eta-s').textContent = `this run, at ${rate(p.rate)}`;
    } else {
      $('mm-f-eta').textContent = d.running && avg ? '≈ ' + dur(waitBytes / avg) : '—';
      $('mm-f-eta-s').textContent = d.running && avg ? `to empty at ${rate(avg)}` : 'to empty';
    }
    $('mm-f-mem').textContent = size(d.memory.dirty);
    const note = $('mm-note');
    note.hidden = d.progressEnabled;
    note.textContent = 'Turn on Settings → Mover Settings → Mover progress for exact totals and ETA.';
  }

  function recentAverage(h) {
    const pts = h.slice(-150).filter((x) => x.parity > 1);
    return pts.length ? pts.reduce((a, x) => a + x.parity, 0) / pts.length * 1048576 : 0;
  }

  function route(d) {
    const c = d.current;
    const card = document.querySelector('#mm .mm-route');
    card.classList.toggle('mm-waiting', !c);
    $('mm-r-label').textContent = c ? 'Moving now' : d.running ? 'Between files' : 'Mover is idle';
    $('mm-r-film').textContent = c ? title(c.rel) : d.running ? (d.queue.next[0] ? 'Next: ' + title(d.queue.next[0].rel) : '—') : 'Nothing moving';
    $('mm-r-path').textContent = c ? c.rel : '';
    $('mm-r-path').title = c ? c.rel : '';
    const pct = c && c.percent != null ? c.percent : 0;
    $('mm-r-pct').textContent = c && c.percent != null ? pct.toFixed(1) + '%' : '';
    $('mm-r-done').style.width = pct + '%';
    $('mm-r-truck').style.left = pct + '%';
    $('mm-r-from').textContent = c && c.source ? c.source.split('/')[2] : 'cache';
    $('mm-r-to').textContent = c && c.disk ? c.disk : '—';
    const q = d.queue;
    $('mm-r-stats').innerHTML = c
      ? `<div><b>${size(c.copied)}</b><span>of ${size(c.size)} on disk</span></div>` +
        `<div><b>${rate(c.rate)}</b><span>write speed</span></div>` +
        `<div><b>${dur(c.eta)}</b><span>left on this file</span></div>` +
        (q.share ? `<div><b>${q.remainingFiles} files</b><span>${size(q.remainingBytes)} left in ${esc(q.share)}</span></div>` : '')
      : '';
  }

  function cage(d) {
    const turboNote = d.turbo ? 'turbo write reads every data drive while one is written' : '';
    $('mm-cage-note').textContent = d.running ? turboNote : '';
    let html = '<div class="mm-bay mm-head"><span>Drive</span><span>Read / write MB/s</span><span>Busy</span><span>Temp</span><span></span><span>Files</span></div>';
    for (const r of d.devices) {
      const full = r.rotational ? HDD_MAX : SSD_MAX;
      const pct = (v) => Math.min(100, v / full * 100).toFixed(1);
      const files = r.writing.map((f) => ({ ...f, w: true })).concat(r.readers);
      const idle = r.read < 1e6 && r.write < 1e6 && !files.length;
      const file = (f) => f
        ? `${app(f.by)}<span class="mm-file ${f.w ? 'mm-w' : ''}" title="${esc(text(f.rel))}">${f.w ? '✎ ' : ''}${esc(base(f.rel))}</span>`
        : '<span></span><span class="mm-file"></span>';
      const temp = parseInt(r.temp, 10);
      html += `<div class="mm-bay ${idle ? 'mm-idle' : ''} ${r.type === 'Parity' ? 'mm-parity' : ''}">` +
        `<div class="mm-name" title="${esc(r.name)}">${esc(r.name)}<small>${esc(r.device)}</small></div>` +
        `<div class="mm-rw" title="read ${rate(r.read)}, write ${rate(r.write)}">` +
          `<div class="mm-t mm-tr"><i style="width:${pct(r.read)}%"></i></div>` +
          `<div class="mm-t mm-tw"><i style="width:${pct(r.write)}%"></i></div>` +
          `<div class="mm-n"><span class="mm-rv">${mbs(r.read)}</span><span class="mm-wv">${mbs(r.write)}</span></div></div>` +
        `<span class="mm-busy ${r.busy >= 85 ? 'mm-hot' : ''}">${r.busy}%</span>` +
        `<span class="mm-temp ${temp >= 50 ? 'mm-warm' : ''}">${isNaN(temp) ? '—' : temp + '°'}</span>` +
        file(files[0]) + '</div>';
      for (const f of files.slice(1)) html += `<div class="mm-bay"><span></span><span></span><span></span><span></span>${file(f)}</div>`;
    }
    $('mm-cage').innerHTML = html;
  }

  // Small files named after a bigger one ("X.mkv.x265-butler.json") ride along as a tag.
  function groupQueue(next, currentRel) {
    const items = [];
    for (const n of next) {
      const parent = items.find((i) => n.rel.startsWith(i.rel + '.')) ||
        (currentRel && n.rel.startsWith(currentRel + '.') ? { rel: currentRel, current: true } : null);
      if (parent && n.size < 1e6) {
        if (parent.current) items.push({ ...n, sidecarOfCurrent: true });
        else parent.extra = (parent.extra || 0) + 1;
        continue;
      }
      items.push({ ...n });
    }
    return items;
  }

  function queue(d) {
    const q = d.queue;
    $('mm-q-note').textContent = q.share ? `${q.share} · in the mover's own order` : '';
    const items = groupQueue(q.next, d.current && d.current.source ? d.current.rel : null);
    const max = Math.max(1, ...items.map((i) => i.size));
    $('mm-queue').innerHTML = items.length
      ? items.map((i) => i.sidecarOfCurrent
        ? `<div class="mm-q"><span class="mm-qt mm-muted">${esc(title(d.current.rel))}<span class="mm-tag">sidecar</span></span><span class="mm-qs">${size(i.size)}</span></div>`
        : `<div class="mm-q"><span class="mm-qt" title="${esc(i.rel)}">${esc(title(i.rel))}${i.extra ? `<span class="mm-tag">+ ${i.extra} small</span>` : ''}</span>` +
          `<span class="mm-qs">${size(i.size)}</span><div class="mm-qbar"><i style="width:${(i.size / max * 100).toFixed(1)}%"></i></div></div>`).join('')
      : `<div class="mm-foot">${d.running ? 'Nothing after this file in this share' : 'The mover is idle. This fills in when it runs.'}</div>`;
    const shown = q.next.length;
    const later = d.waiting.filter((w) => w.share !== q.share && w.files);
    const parts = [];
    if (d.running && q.remainingFiles > shown) parts.push(`then ${q.remainingFiles - shown} more files in ${q.share}`);
    if (d.running && later.length) parts.push('then ' + later.map((w) => `${w.share} (${w.files} files, ${size(w.bytes)})`).join(', '));
    $('mm-q-foot').textContent = parts.join(', ');
  }

  function waiting(d) {
    const total = d.waiting.reduce((a, w) => a + w.bytes, 0) || 1;
    $('mm-waiting').innerHTML = d.waiting.length
      ? d.waiting.map((w) => `<div class="mm-share"><b title="${esc(w.share)}">${esc(w.share)}</b>` +
          `<div class="mm-meter"><i style="width:${(w.bytes / total * 100).toFixed(1)}%"></i></div>` +
          `<span class="mm-v">${size(w.bytes)}</span><small>${w.files} files on ${esc(w.pool)}</small></div>`).join('')
      : '<div class="mm-foot">Nothing waiting on a cache pool.</div>';
    const m = d.memory, vm = m.vm;
    // With byte limits the gauge runs to the hold point; with ratios, to the ratio of RAM is unknown here, so to 2x what is in memory.
    const hold = vm.dirty_bytes || 0, start = vm.dirty_background_bytes || 0;
    const scale = hold || Math.max(m.dirty * 2, 1);
    $('mm-mem-v').textContent = hold ? `${size(m.dirty)} of ${size(hold)}` : size(m.dirty);
    $('mm-mem-bar').style.width = Math.min(100, m.dirty / scale * 100).toFixed(1) + '%';
    const mark = $('mm-mem-mark');
    mark.hidden = !start;
    if (start) mark.style.left = (start / scale * 100).toFixed(1) + '%';
    $('mm-mem-start').textContent = start ? `flushing starts at ${size(start)}` : `flushing starts at ${vm.dirty_background_ratio}% of RAM`;
    $('mm-mem-hold').textContent = hold ? `writers wait at ${size(hold)}` : `writers wait at ${vm.dirty_ratio}% of RAM`;
  }

  function chart(d) {
    const cv = $('mm-chart');
    const w = cv.clientWidth, h = cv.clientHeight || 64;
    if (!w) return;
    const dpr = window.devicePixelRatio || 1;
    if (cv.width !== Math.round(w * dpr) || cv.height !== Math.round(h * dpr)) { cv.width = Math.round(w * dpr); cv.height = Math.round(h * dpr); }
    const g = cv.getContext('2d');
    g.setTransform(dpr, 0, 0, dpr, 0, 0);
    g.clearRect(0, 0, w, h);
    const cs = getComputedStyle($('mm'));
    const accent = cs.getPropertyValue('--mm-accent').trim(), ink = cs.getPropertyValue('--mm-muted').trim();
    const pts = d.history, span = 900, start = d.now - span;
    const top = Math.max(200, Math.ceil(Math.max(0, ...pts.map((p) => p.parity)) / 100) * 100);
    const x = (t) => ((t - start) / span) * (w - 34) + 30;
    const y = (v) => h - 4 - (v / top) * (h - 10);
    g.font = '10px ' + cs.getPropertyValue('--mm-mono'); g.fillStyle = ink; g.globalAlpha = .7;
    g.fillText(String(top), 0, y(top) + 8); g.fillText('0', 0, y(0));
    g.globalAlpha = .25; g.fillRect(30, y(top / 2), w - 34, .5); g.fillRect(30, y(0), w - 34, .5);
    g.globalAlpha = .55; g.strokeStyle = ink; g.lineWidth = 1; g.setLineDash([3, 3]);
    for (let i = 1; i < pts.length; i++) {
      if (pts[i].file && pts[i].file !== pts[i - 1].file) { g.beginPath(); g.moveTo(x(pts[i].t), 2); g.lineTo(x(pts[i].t), h - 4); g.stroke(); }
    }
    g.setLineDash([]); g.globalAlpha = 1;
    if (!pts.length) return;
    const grad = g.createLinearGradient(0, 0, 0, h);
    grad.addColorStop(0, accent); grad.addColorStop(1, 'transparent');
    g.globalAlpha = .3; g.beginPath(); g.moveTo(x(pts[0].t), y(0));
    pts.forEach((p) => g.lineTo(x(p.t), y(p.parity)));
    g.lineTo(x(pts[pts.length - 1].t), y(0)); g.fillStyle = grad; g.fill();
    g.globalAlpha = 1; g.beginPath();
    pts.forEach((p, i) => (i ? g.lineTo(x(p.t), y(p.parity)) : g.moveTo(x(p.t), y(p.parity))));
    g.strokeStyle = accent; g.lineWidth = 1.5; g.stroke();
    const last = pts[pts.length - 1];
    g.beginPath(); g.arc(x(last.t), y(last.parity), 3, 0, Math.PI * 2); g.fillStyle = accent; g.fill();
  }

  let last = null;
  async function poll() {
    try {
      const d = await (await fetch(URL, { cache: 'no-store' })).json();
      last = d;
      strip(d); route(d); cage(d); queue(d); waiting(d); chart(d);
    } catch (e) {
      const note = $('mm-note');
      note.hidden = false;
      note.textContent = 'Could not read mover status: ' + e.message;
    }
    setTimeout(poll, document.hidden ? POLL_MS * 5 : POLL_MS);
  }
  addEventListener('resize', () => last && chart(last));
  poll();
})();
