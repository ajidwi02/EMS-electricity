/* EMS lvmdp trend DB (L-T + P-H fase 2): hydrate dari #trends-data JSON,
   hover crosshair + dot + tooltip, refresh 60s via GET data-refresh. Tanpa lib.
   CP-049: skala adaptif -> dekorasi skala (grid/refs/limit/label sumbu/chip)
   dirender ulang saat refresh; null = '—', di luar batas dasar = '↑'/'↓'. */
(function () {
  'use strict';

  var root = document.getElementById('lvmdp-root');
  if (!root) return;

  var srcEl = document.getElementById('trends-data');
  var refreshUrl = root.getAttribute('data-refresh') || '';
  if (!srcEl || !refreshUrl) return;

  var data = null;
  try { data = JSON.parse(srcEl.textContent || '{}'); } catch (e) { return; }

  var SERIES = { ln: ['R', 'S', 'T'], ll: ['R', 'S', 'T'], a: ['R', 'S', 'T'], ph: ['K'] };
  var UNITS = { ln: 'V', ll: 'V', a: 'A', ph: 'kW' };
  var DEC = { ln: 2, ll: 2, a: 0, ph: 2 };
  var LETTERS = { R: 'R', S: 'S', T: 'T', K: '' };   /* FASE-H CP-064: huruf seri di tooltip (selaras legend R/S/T kartu); K = tanpa label */
  var ST_DEC = { ln: 1, ll: 1, a: 0, ph: 1 };
  var REFRESH_MS = 60000;

  function fmt(n, dec) { return (n === null || n === undefined) ? '—' : Number(n).toFixed(dec); }

  function ch(key) { return (key === 'ph') ? data.power : data.charts[key]; }

  /* panah status vs batas dasar skala (sinkron $trend_val di view) */
  function mark(v, sc) {
    if (v === null || v === undefined || !sc || sc.limit === undefined) return '';
    var lim = Number(sc.limit);
    var base = (sc.base_min !== undefined) ? Number(sc.base_min) : lim;
    if (v > lim + 0.0001) return ' ↑';
    if (v < base - 0.0001) return ' ↓';
    return '';
  }

  function mapPoints(pts, ymin, ymax) {
    var out = [], i, m, v;
    for (i = 0; i < pts.length; i++) {
      m = pts[i][0]; v = pts[i][1];
      out.push((m / 1440 * 800).toFixed(2) + ',' + (300 - 300 * (v - ymin) / (ymax - ymin)).toFixed(2));
    }
    return out.join(' ');
  }

  function decoLine(y, stroke, width, dash) {
    var l = document.createElementNS('http://www.w3.org/2000/svg', 'line');
    l.setAttribute('x1', '0');
    l.setAttribute('x2', '800');
    l.setAttribute('y1', y.toFixed(2));
    l.setAttribute('y2', y.toFixed(2));
    l.setAttribute('stroke', stroke);
    l.setAttribute('stroke-width', width);
    l.setAttribute('vector-effect', 'non-scaling-stroke');
    if (dash) l.setAttribute('stroke-dasharray', dash);
    return l;
  }

  function clearChildren(el) { while (el.firstChild) el.removeChild(el.firstChild); }

  /* render ulang seluruh dekorasi tergantung skala (grid, refs, limit,
     label sumbu-Y, chip refs) — dipanggil tiap paint karena skala bisa
     meluas saat data baru masuk */
  function renderDeco(key) {
    var c = ch(key), sc = c.scale;
    var box = root.querySelector('[data-trend="' + key + '"]');
    if (!box) return;
    var svg = box.querySelector('svg');
    var deco = svg ? svg.querySelector('[data-deco]') : null;
    var rng = sc.ymax - sc.ymin;
    function yPix(v) { return 300 - 300 * (v - sc.ymin) / rng; }
    if (deco) {
      clearChildren(deco);
      var yv;
      for (yv = sc.ymin; yv <= sc.ymax + 0.001; yv += sc.ystep) {
        deco.appendChild(decoLine(yPix(yv), '#E3E5E0', '1', ''));
      }
      (sc.refs || []).forEach(function (ref) {
        deco.appendChild(decoLine(yPix(ref.v), ref.c, '1.5', '6 3'));
      });
      if (sc.expanded && sc.limit > sc.ymin && sc.limit < sc.ymax) {
        deco.appendChild(decoLine(yPix(sc.limit), '#B97A0C', '1.5', '2 4'));
      }
    }
    var yax = root.querySelector('[data-yaxis="' + key + '"]');
    if (yax) {
      clearChildren(yax);
      for (var yv2 = sc.ymin; yv2 <= sc.ymax + 0.001; yv2 += sc.ystep) {
        var s = document.createElement('span');
        s.className = 'absolute right-2 -translate-y-1/2 text-[10px] tabular-nums text-ems-muted';
        s.style.top = ((sc.ymax - yv2) / rng * 100).toFixed(2) + '%';
        s.textContent = String(Math.round(yv2));
        yax.appendChild(s);
      }
    }
    var chips = root.querySelector('[data-refchips="' + key + '"]');
    if (chips) {
      clearChildren(chips);
      (sc.refs || []).forEach(function (ref) {
        var s2 = document.createElement('span');
        s2.className = 'absolute right-2 -translate-y-1/2 rounded border border-ems-line bg-white/90 px-1.5 py-px text-[10px] font-medium text-ems-muted';
        s2.style.top = ((1 - (ref.v - sc.ymin) / rng) * 100).toFixed(2) + '%';
        s2.textContent = ref.label;
        chips.appendChild(s2);
      });
    }
  }

  /* teks "Skala x–y · batas z" di header kartu — tampil hanya saat skala meluas */
  function renderScaleInfo(key) {
    var sc = ch(key).scale;
    var h3 = root.querySelector('[data-trendhead="' + key + '"]');
    if (!h3) return;
    var si = h3.querySelector('[data-scaleinfo]');
    if (!sc.expanded) {
      if (si && si.parentNode) si.parentNode.removeChild(si);
      return;
    }
    if (!si) {
      si = document.createElement('span');
      si.setAttribute('data-scaleinfo', '');
      si.className = 'ml-auto shrink-0 text-[11px] tabular-nums text-ems-muted';
      h3.appendChild(si);
    }
    clearChildren(si);
    si.appendChild(document.createTextNode('Skala ' + Math.round(sc.ymin) + '–' + Math.round(sc.ymax) + ' ' + UNITS[key] + ' · '));
    var b = document.createElement('span');
    b.className = 'text-ems-amberdeep';
    b.textContent = 'batas ' + Math.round(sc.limit);
    si.appendChild(b);
  }

  function nearestIdx(pts, target) {
    var lo = 0, hi = pts.length - 1, mid;
    if (pts.length === 0) return -1;
    while (lo < hi) { mid = (lo + hi) >> 1; if (pts[mid][0] < target) lo = mid + 1; else hi = mid; }
    if (lo > 0 && pts[lo][0] - target >= target - pts[lo - 1][0]) lo = lo - 1;
    return lo;
  }

  function menLbl(men) {
    var h = ('0' + Math.floor(men / 60)).slice(-2);
    var m = ('0' + (men % 60)).slice(-2);
    return h + ':' + m;
  }

  function allMenit(key) {
    var c = ch(key), men = {}, list, i;
    var keys = SERIES[key];
    for (i = 0; i < keys.length; i++) {
      list = c.series[keys[i]].pts;
      for (var j = 0; j < list.length; j++) men[list[j][0]] = 1;
    }
    return Object.keys(men).map(Number).sort(function (x, y) { return x - y; });
  }

  function paint(key) {
    var c = ch(key);
    var box = root.querySelector('[data-trend="' + key + '"]');
    if (!box) return;
    var svg = box.querySelector('svg');
    var sc = c.scale;
    renderDeco(key);
    if (key !== 'ph') renderScaleInfo(key);
    var keys = SERIES[key], i, sk;
    for (i = 0; i < keys.length; i++) {
      sk = keys[i];
      var pl = svg ? svg.querySelector('polyline[data-series="' + sk + '"]') : null;
      if (pl) {
        var pts = c.series[sk].pts;
        pl.setAttribute('points', mapPoints(pts, sc.ymin, sc.ymax));
        pl.setAttribute('display', pts.length ? '' : 'none');
        /* sisip titik-tunggal (1 titik hari ini) + bersihkan sisa repaint */
        var prev = svg.querySelector('circle[data-solo="' + sk + '"]');
        if (prev && prev.parentNode) prev.parentNode.removeChild(prev);
        if (pts.length === 1) {
          var solo = document.createElementNS('http://www.w3.org/2000/svg', 'circle');
          solo.setAttribute('cx', (pts[0][0] / 1440 * 800).toFixed(2));
          solo.setAttribute('cy', (300 - 300 * (pts[0][1] - sc.ymin) / (sc.ymax - sc.ymin)).toFixed(2));
          solo.setAttribute('r', '3');
          solo.setAttribute('data-solo', sk);
          pl.parentNode.appendChild(solo);
        }
      }
      ['max', 'avg', 'min'].forEach(function (fld) {
        var cell = root.querySelector('[data-cell="' + key + ':' + sk + ':' + fld + '"]');
        if (!cell) return;
        var st = c.series[sk].stat || {};
        var num = cell.firstChild;
        if (num) {
          num.textContent = (st[fld] === null || st[fld] === undefined ? '—' : Number(st[fld]).toFixed(ST_DEC[key])) + mark(st[fld], sc) + ' ' + UNITS[key];
          var sib = num.nextElementSibling;
          if (sib) sib.textContent = st['r' + fld] || '-';
        }
      });
    }
    if (key === 'ph') {
      var peak = c.peak;
      var chip = root.querySelector('[data-cell="ph:peak"]');
      if (chip && peak && peak.val !== null) {
        chip.textContent = 'Maks ' + Number(peak.val).toFixed(1) + ' kW ' + peak.t;
        chip.style.top = peak.cy + '%';
        chip.style.left = peak.x + '%';
        var dotp = svg ? svg.querySelector('circle[data-peakdot]') : null;
        if (dotp && !dotp.getAttribute('cx')) {
          dotp.setAttribute('cx', peak.cpx); dotp.setAttribute('cy', peak.cpy);
          dotp.setAttribute('r', '3'); dotp.setAttribute('fill', '#191C1F');
        }
      }
      ['max', 'avg', 'min'].forEach(function (fld) {
        var cell = root.querySelector('[data-cell="ph:' + fld + '"]');
        if (!cell) return;
        var st = c.stat || {};
        cell.textContent = (st[fld] === null || st[fld] === undefined ? '—' : Number(st[fld]).toFixed(1));
        var sib = cell.nextElementSibling ? cell.nextElementSibling.nextElementSibling : null;
        if (sib && sib.className && String(sib.className).indexOf('text-[10px]') !== -1) {
          sib.textContent = st['r' + fld] || '-';
        }
      });
    }
    var dax = root.querySelector('[data-date="' + (key === 'ph' ? 'ph' : 'lt') + '"]');
    if (dax && data.tgl) dax.textContent = data.tgl;
  }

  function paintAll() { ['ln', 'll', 'a', 'ph'].forEach(function (k) { paint(k); }); }

  /* ---- hover (FASE-H, CP-064): transisi halus pola tarif7 (is-shown) +
     huruf seri + ring dot emfasis + highlight label sumbu-x +
     snap-through-refresh 60 s + inspeksi sentuh. Kontrak data BEKU (CP-042). ---- */
  var hoverState = { key: null, men: -1 };

  function clearXLabel(key) {
    var rowEl = root.querySelector('[data-xrow="' + key + '"]');
    if (!rowEl) return;
    Array.prototype.forEach.call(rowEl.querySelectorAll('[data-xlbl].is-active'), function (e) {
      e.classList.remove('is-active');
    });
  }

  function markXLabel(key, men) {
    var rowEl = root.querySelector('[data-xrow="' + key + '"]');
    if (!rowEl) return;
    var want = String(Math.max(0, Math.min(1440, Math.round(men / 180) * 180)));
    Array.prototype.forEach.call(rowEl.querySelectorAll('[data-xlbl]'), function (e) {
      if (e.getAttribute('data-xlbl') === want) { e.classList.add('is-active'); }
      else { e.classList.remove('is-active'); }
    });
  }

  /* menit tercatat terdekat dari daftar ANGKA menit (nearestIdx di atas khusus
     array pair [men, nilai]; baseline keliru memakai nearestIdx utk menList). */
  function nearestMen(list, target) {
    var lo = 0, hi = list.length - 1, mid;
    if (!list.length) return -1;
    while (lo < hi) { mid = (lo + hi) >> 1; if (list[mid] < target) lo = mid + 1; else hi = mid; }
    if (lo > 0 && list[lo] - target >= target - list[lo - 1]) lo = lo - 1;
    return list[lo];
  }

  function showHover(key, men) {
    var c = ch(key);
    var box = root.querySelector('[data-trend="' + key + '"]');
    if (!box) return;
    var hc = box.querySelector('[data-hx]');
    var tip = box.querySelector('[data-tip]');
    var sc = c.scale;
    var keys = SERIES[key];
    var loc = men / 1440 * 100;

    hoverState.key = key;
    hoverState.men = men;

    if (hc) {
      hc.classList.remove('hidden');
      hc.classList.add('is-shown');
      hc.style.left = loc + '%';
    }
    markXLabel(key, men);

    if (tip) {
      while (tip.firstChild) tip.removeChild(tip.firstChild);
      var head = document.createElement('span');
      head.className = 'font-semibold';
      head.textContent = menLbl(men);
      tip.appendChild(head);
    }

    for (var i = 0; i < keys.length; i++) {
      var sk = keys[i];
      var dotEl = box.querySelector('[data-dot="' + sk + '"]');
      var pts = c.series[sk].pts;
      var idx = nearestIdx(pts, men);
      var v = (idx >= 0 && pts[idx][0] === men) ? pts[idx][1] : null;

      if (dotEl && v !== null) {
        dotEl.classList.remove('hidden');
        dotEl.classList.add('is-active');
        dotEl.style.left = (pts[idx][0] / 1440 * 100) + '%';
        dotEl.style.top = ((1 - (v - sc.ymin) / (sc.ymax - sc.ymin)) * 100) + '%';
      } else if (dotEl) {
        dotEl.classList.add('hidden');
        dotEl.classList.remove('is-active');
      }

      if (tip) {
        var row = document.createElement('div');
        row.className = 'flex items-center gap-1.5';
        if (LETTERS[sk]) {
          var dlbl = document.createElement('span');
          dlbl.className = 'w-3 shrink-0 font-medium text-ems-muted';
          dlbl.textContent = LETTERS[sk];
          row.appendChild(dlbl);
        }
        var ddot = document.createElement('span');
        ddot.className = 'size-2 shrink-0 rounded-full';
        ddot.style.background = (sk === 'R') ? '#E53935' : (sk === 'S') ? '#F9A825' : '#191C1F';
        row.appendChild(ddot);
        var dval = document.createElement('span');
        dval.className = 'font-medium ml-auto';
        dval.textContent = (v === null ? '—' : Number(v).toFixed(DEC[key])) + ' ' + UNITS[key];
        row.appendChild(dval);
        tip.appendChild(row);
      }
    }

    if (tip) {
      tip.classList.remove('hidden');
      tip.style.top = '6px';
      tip.style.left = Math.min(Math.max(loc, 10), 90) + '%';
      /* TRANSISI DIPEGANG CSS (state kelas) — dilarang menulis inline transform di JS */
      requestAnimationFrame(function () { tip.classList.add('is-shown'); });
    }
  }

  function hideHover(key) {
    var box = root.querySelector('[data-trend="' + key + '"]');
    if (!box) return;
    hoverState.key = null;
    hoverState.men = -1;
    var hc = box.querySelector('[data-hx]');
    if (hc) { hc.classList.add('hidden'); hc.classList.remove('is-shown'); }
    clearXLabel(key);
    Array.prototype.forEach.call(box.querySelectorAll('[data-dot]'), function (e) {
      e.classList.add('hidden');
      e.classList.remove('is-active');
    });
    var tip = box.querySelector('[data-tip]');
    if (tip) {
      tip.classList.remove('is-shown');   /* fade-out murni via CSS */
      setTimeout(function () {
        if (!tip.classList.contains('is-shown')) tip.classList.add('hidden');
      }, 200);
    }
  }

  Object.keys(SERIES).forEach(function (key) {
    var box = root.querySelector('[data-trend="' + key + '"]');
    if (!box) return;
    function onMove(ev) {
      var menList = allMenit(key);
      if (!menList.length) return;
      var rect = box.getBoundingClientRect();
      var f = ((ev.clientX - rect.left) / rect.width);
      f = Math.max(0, Math.min(1, f));
      showHover(key, nearestMen(menList, Math.round(f * 1440)));
    }
    box.addEventListener('pointermove', onMove);
    box.addEventListener('pointerdown', function (ev) {
      if (ev.pointerType === 'mouse') return;   /* mouse: pointermove sudah cukup */
      onMove(ev);                               /* sentuh/pen: tekan = inspeksi (D7) */
    });
    box.addEventListener('pointerup', function (ev) {
      if (ev.pointerType !== 'mouse') hideHover(key);   /* lepas = tutup */
    });
    ['pointercancel', 'pointerleave'].forEach(function (t) {
      box.addEventListener(t, function () { hideHover(key); });
    });
    box.addEventListener('focusout', function () { hideHover(key); });
    box.addEventListener('keydown', function (ev) {
      var menList = allMenit(key);
      if (!menList.length) { return; }
      var lo = menList[0], hi = menList[menList.length - 1];
      var now = (hoverState.key === key) ? hoverState.men : -1;
      if (now === -1) now = lo;
      if (ev.key === 'ArrowRight') now += 5;
      else if (ev.key === 'ArrowLeft') now -= 5;
      else if (ev.key === 'Home') now = lo;
      else if (ev.key === 'End') now = hi;
      else if (ev.key === 'Escape') { hideHover(key); ev.preventDefault(); return; }
      else return;
      ev.preventDefault();
      now = Math.max(lo, Math.min(hi, now));
      showHover(key, nearestMen(menList, now));
    });
  });

  /* refresh 60 s (CP-046) — FASE-H D6: state hover DIPERTAHANKAN;
     setelah repaint, re-snap ke menit tercatat terdekat; data hilang -> tutup. */
  function tick() {
    if (document.hidden) return;
    var xhr;
    try { xhr = new XMLHttpRequest(); } catch (e) { return; }
    xhr.open('GET', refreshUrl, true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState !== 4 || xhr.status !== 200) return;
      try { data = JSON.parse(xhr.responseText); } catch (e) { return; }
      srcEl.textContent = xhr.responseText;
      paintAll();
      if (hoverState.key !== null) {
        var menList = allMenit(hoverState.key);
        if (menList.length) {
          var last = hoverState.men;
          last = Math.max(menList[0], Math.min(menList[menList.length - 1],
            (last === -1) ? menList[0] : last));
          showHover(hoverState.key, nearestMen(menList, last));
        } else {
          hideHover(hoverState.key);
        }
      }
    };
    xhr.send();
  }
  setInterval(tick, REFRESH_MS);

  paintAll();
})();
