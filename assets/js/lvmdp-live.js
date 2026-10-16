/* EMS LVMDP live: MQTT over WebSocket, display saja (tanpa DB). + 9 gauge SVG (G2). */
(function () {
  'use strict';

  var root = document.getElementById('lvmdp-root');
  if (!root) return;

  var connEl = document.getElementById('lvmdp-conn');
  var dotEl = document.getElementById('lvmdp-dot');
  var updatedEl = document.getElementById('lvmdp-updated');
  var devtimeEl = document.getElementById('lvmdp-devtime');
  var fields = root.querySelectorAll('[data-f]');

  var wsUrl = root.getAttribute('data-ws') || '';
  var topic = root.getAttribute('data-topic') || 'data/ems/lvmdp/+';
  var user = root.getAttribute('data-user') || '';
  var pass = root.getAttribute('data-pass') || '';

  if (!wsUrl) {
    setConn('Konfigurasi WS kosong', 'idle');
    return;
  }

  var STALE_MS = 5000;
  var lastSeen = 0;

  var timeFmt = null;
  var dateFmt = null;
  try {
    timeFmt = new Intl.DateTimeFormat('id-ID', {
      timeZone: 'Asia/Jakarta', hour: '2-digit', minute: '2-digit', second: '2-digit', hour12: false
    });
    dateFmt = new Intl.DateTimeFormat('id-ID', {
      timeZone: 'Asia/Jakarta', day: 'numeric', month: 'short', year: 'numeric'
    });
  } catch (err) {
    timeFmt = null;
    dateFmt = null;
  }

  function setText(el, s) {
    if (el) el.textContent = s;
  }

  function setConn(text, mode) {
    setText(connEl, text);
    if (!dotEl) return;
    var base = 'size-2 shrink-0 rounded-full ';
    if (mode === 'live') {
      dotEl.className = base + 'bg-ems-ok live-dot';
    } else if (mode === 'stale') {
      dotEl.className = base + 'bg-ems-amber';
    } else {
      dotEl.className = base + 'bg-ems-muted';
    }
  }

  function numOrNull(v) {
    if (v === null || v === undefined || v === '') return null;
    var n = Number(v);
    if (!isFinite(n)) return null;
    return n;
  }

  function pickKwh(d) {
    if (d.Kwh !== undefined && d.Kwh !== null && d.Kwh !== '') return d.Kwh;
    return d.kwh;
  }

  function isOurTopic(t) {
    var p = String(t).split('/');
    return p.length === 4 && p[0] === 'data' && p[1] === 'ems' && p[2] === 'lvmdp';
  }

  function formatNowWib() {
    var now = new Date();
    if (timeFmt && dateFmt) {
      return dateFmt.format(now) + ' ' + timeFmt.format(now).replace(/\./g, ':') + ' WIB';
    }
    return now.toString().slice(0, 24);
  }

  function formatTerminalTime(s) {
    var raw = String(s === undefined || s === null ? '' : s).slice(0, 30);
    var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,3}))?$/.exec(raw);
    if (!m) return raw === '' ? '-' : raw;
    var ms = m[7] ? m[7] : '0';
    while (ms.length < 3) ms += '0';
    var label = m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5] + ':' + m[6] + ' WIB';
    void ms;
    return label;
  }

  /* ------------------------------------------------------------------
   * G2 — Gauge renderer. Tabel zona LOCKED (plans/ems-lvmdp-gauge-charts.md §2.3).
   * Dial: viewBox 0 0 120 70, pusat (60,58), radius 48. Jarum menunjuk ke
   * atas pada rotate(0); minimum = -90 (kiri), maksimum = +90 (kanan).
   * Animasi: interpolasi rAF 650ms ease-out; instan bila prefers-reduced-motion.
   * ------------------------------------------------------------------ */
  var SVGNS = 'http://www.w3.org/2000/svg';
  var C_OK = '#1A7F4D';
  var C_WARN = '#EAA500';
  var C_BAD = '#C2410C';
  var C_IDLE = '#9AA1AD';
  var C_TICKTXT = '#6B7280';
  var C_INK = '#191C1F';
  var GX = 60;
  var GY = 58;
  var GRAD = 48;
  var ANIM_MS = 650;

  var REDUCED = false;
  try {
    REDUCED = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  } catch (e) {
    REDUCED = false;
  }

  var GAUGES = {
    ln: { min: 0, max: 300, nom: 230, segs: [[0, 198, C_BAD], [198, 207, C_WARN], [207, 253, C_OK], [253, 264, C_WARN], [264, 300, C_BAD]] },
    ll: { min: 0, max: 500, nom: 400, segs: [[0, 340, C_BAD], [340, 360, C_WARN], [360, 440, C_OK], [440, 455, C_WARN], [455, 500, C_BAD]] },
    a: { min: 0, max: 1200, nom: 1000, segs: [[0, 800, C_OK], [800, 900, C_WARN], [900, 1200, C_BAD]] }
  };

  function gFrac(v, mn, mx) {
    var f = (v - mn) / (mx - mn);
    if (!isFinite(f)) return 0;
    if (f < 0) return 0;
    if (f > 1) return 1;
    return f;
  }

  function gAngle(f) {
    return -90 + 180 * f;
  }

  function gZone(group, v) {
    if (v === null || v === undefined || !isFinite(v) || v < 0) return null;
    if (group === 'ln') {
      if (v < 198) return { color: C_BAD, label: 'Bahaya' };
      if (v < 207) return { color: C_WARN, label: 'Waspada' };
      if (v <= 253) return { color: C_OK, label: 'Normal' };
      if (v <= 264) return { color: C_WARN, label: 'Waspada' };
      return { color: C_BAD, label: 'Bahaya' };
    }
    if (group === 'll') {
      if (v < 340) return { color: C_BAD, label: 'Bahaya' };
      if (v < 360) return { color: C_WARN, label: 'Waspada' };
      if (v <= 440) return { color: C_OK, label: 'Normal' };
      if (v <= 455) return { color: C_WARN, label: 'Waspada' };
      return { color: C_BAD, label: 'Bahaya' };
    }
    if (group === 'a') {
      if (v < 800) return { color: C_OK, label: 'Normal' };
      if (v <= 900) return { color: C_WARN, label: 'Waspada' };
      return { color: C_BAD, label: 'Bahaya' };
    }
    return null;
  }

  function gPt(val, cfg) {
    var t = (180 - 180 * ((val - cfg.min) / (cfg.max - cfg.min))) * Math.PI / 180;
    return [GX + GRAD * Math.cos(t), GY - GRAD * Math.sin(t)];
  }

  function gArcD(a, b, cfg) {
    var p1 = gPt(a, cfg);
    var p2 = gPt(b, cfg);
    return 'M ' + p1[0].toFixed(2) + ' ' + p1[1].toFixed(2) +
      ' A ' + GRAD + ' ' + GRAD + ' 0 0 1 ' + p2[0].toFixed(2) + ' ' + p2[1].toFixed(2);
  }

  function elNS(tag, attrs) {
    var e = document.createElementNS(SVGNS, tag);
    var k;
    for (k in attrs) {
      if (Object.prototype.hasOwnProperty.call(attrs, k)) e.setAttribute(k, attrs[k]);
    }
    return e;
  }

  function easeOut(p) {
    if (p < 0) return 0;
    if (p > 1) return 1;
    return 1 - Math.pow(1 - p, 3);
  }

  function nowMs() {
    if (typeof performance !== 'undefined' && performance.now) return performance.now();
    return Date.now();
  }

  var gauges = [];
  var animOn = false;

  function paintAngle(g, v) {
    g.needle.setAttribute('transform', 'rotate(' + gAngle(gFrac(v, g.cfg.min, g.cfg.max)) + ' 60 58)');
  }

  function snapPark(g) {
    g.needle.setAttribute('transform', 'rotate(-90 60 58)');
    g.line.setAttribute('stroke', C_IDLE);
    if (g.dot) g.dot.setAttribute('fill', C_IDLE);
    setText(g.status, '-');
    g.disp = null;
    g.active = false;
  }

  function paintZone(g, n) {
    var z = gZone(g.group, n);
    var col = z ? z.color : C_IDLE;
    var lab = z ? z.label : '-';
    g.line.setAttribute('stroke', col);
    if (g.dot) g.dot.setAttribute('fill', col);
    setText(g.status, lab);
  }

  function finishGauge(g) {
    g.disp = g.to;
    paintAngle(g, g.to);
    if (g.dig) setText(g.dig, g.to.toFixed(g.dec));
    g.active = false;
  }

  function tickGauges(t) {
    var any = false;
    var i, g, p, v;
    for (i = 0; i < gauges.length; i++) {
      g = gauges[i];
      if (!g.active) continue;
      p = (t - g.t0) / ANIM_MS;
      if (p >= 1) {
        finishGauge(g);
      } else {
        v = g.from + (g.to - g.from) * easeOut(p);
        paintAngle(g, v);
        if (g.dig) setText(g.dig, v.toFixed(g.dec));
        any = true;
      }
    }
    if (any) {
      if (window.requestAnimationFrame) {
        window.requestAnimationFrame(tickGauges);
      } else {
        for (i = 0; i < gauges.length; i++) {
          if (gauges[i].active) finishGauge(gauges[i]);
        }
        animOn = false;
      }
    } else {
      animOn = false;
    }
  }

  function kickGauges() {
    if (animOn) return;
    animOn = true;
    if (window.requestAnimationFrame) {
      window.requestAnimationFrame(tickGauges);
    } else {
      animOn = false;
    }
  }

  function updateGauges(d) {
    var i, g, raw, n;
    for (i = 0; i < gauges.length; i++) {
      g = gauges[i];
      raw = d[g.key];
      n = numOrNull(raw);
      if (n !== null && n < 0) n = null; // gauge tak menampilkan negatif; digital tetap apa adanya
      if (n === null) {
        snapPark(g);
        continue;
      }
      paintZone(g, n);
      if (REDUCED || g.disp === null) {
        g.disp = n;
        g.active = false;
        paintAngle(g, n);
      } else if (n !== g.disp) {
        g.from = g.disp;
        g.to = n;
        g.t0 = nowMs();
        g.active = true;
        kickGauges();
      }
    }
  }

  function initGauges() {
    var svgs = root.querySelectorAll('svg.gauge');
    var k, s;
    for (k = 0; k < svgs.length; k++) {
      (function (svg) {
        var group = svg.getAttribute('data-gauge');
        var key = svg.getAttribute('data-f');
        if (!group || !Object.prototype.hasOwnProperty.call(GAUGES, group) || !key) return;
        var cfg = GAUGES[group];
        var tnom, an, ro, ri;
        for (s = 0; s < cfg.segs.length; s++) {
          svg.appendChild(elNS('path', {
            d: gArcD(cfg.segs[s][0], cfg.segs[s][1], cfg),
            fill: 'none', stroke: cfg.segs[s][2], 'stroke-width': 10
          }));
        }
        an = (180 - 180 * ((cfg.nom - cfg.min) / (cfg.max - cfg.min))) * Math.PI / 180;
        ro = [GX + GRAD * Math.cos(an), GY - GRAD * Math.sin(an)];
        ri = [GX + (GRAD - 8) * Math.cos(an), GY - (GRAD - 8) * Math.sin(an)];
        svg.appendChild(elNS('line', {
          x1: ro[0].toFixed(2), y1: ro[1].toFixed(2),
          x2: ri[0].toFixed(2), y2: ri[1].toFixed(2),
          stroke: C_INK, 'stroke-width': 2
        }));
        var tmin = elNS('text', { x: 6, y: 68, 'font-size': 8, fill: C_TICKTXT, 'text-anchor': 'start' });
        tmin.textContent = String(cfg.min);
        svg.appendChild(tmin);
        var tmax = elNS('text', { x: 114, y: 68, 'font-size': 8, fill: C_TICKTXT, 'text-anchor': 'end' });
        tmax.textContent = String(cfg.max);
        svg.appendChild(tmax);
        var needle = elNS('g', { id: 'ndl-' + key, transform: 'rotate(-90 60 58)' });
        var line = elNS('line', { x1: GX, y1: GY, x2: GX, y2: GY - 40, stroke: C_IDLE, 'stroke-width': 3, 'stroke-linecap': 'round' });
        needle.appendChild(line);
        needle.appendChild(elNS('circle', { cx: GX, cy: GY, r: 4.5, fill: C_INK }));
        svg.appendChild(needle);
        var dig = root.querySelector('span[data-f="' + key + '"]');
        var dec = 1;
        if (dig) {
          var pd = parseInt(dig.getAttribute('data-dec') || '1', 10);
          if (!isNaN(pd) && pd >= 0 && pd <= 3) dec = pd;
        }
        gauges.push({
          key: key, group: group, cfg: cfg, needle: needle, line: line,
          dot: document.getElementById('dot-' + key),
          status: document.getElementById('gst-' + key),
          dig: dig, dec: dec, disp: null, from: 0, to: 0, t0: 0, active: false
        });
      })(svgs[k]);
    }
  }

  try {
    initGauges();
  } catch (e) {
    /* tanpa gauge, angka digital tetap jalan */
  }

  function render(d) {
    if (typeof d !== 'object' || d === null) return;
    if (d._groupName !== 'lvmdp') return;
    var i;
    for (i = 0; i < fields.length; i++) {
      (function (el) {
        if (el.tagName && String(el.tagName).toLowerCase() === 'svg') return; // slot gauge: angkanya di-tween updateGauges
        var key = el.getAttribute('data-f');
        var dec = parseInt(el.getAttribute('data-dec') || '1', 10);
        if (isNaN(dec) || dec < 0 || dec > 3) dec = 1;
        var raw = (key === 'Kwh') ? pickKwh(d) : d[key];
        var n = numOrNull(raw);
        setText(el, n === null ? '-' : n.toFixed(dec));
      })(fields[i]);
    }
    setText(devtimeEl, formatTerminalTime(d._terminalTime));
    setText(updatedEl, formatNowWib());
    lastSeen = Date.now();
    setConn('Live', 'live');
    try {
      updateGauges(d);
    } catch (e) {
      /* gauge tidak boleh merusak angka digital */
    }
  }

  setInterval(function () {
    if (lastSeen === 0) return;
    if (Date.now() - lastSeen > STALE_MS) {
      setConn('Data tidak masuk', 'stale');
    }
  }, 1000);

  if (typeof window.mqtt === 'undefined' || !window.mqtt.connect) {
    setConn('Gagal muat library MQTT', 'idle');
    return;
  }

  if (window.lucide && window.lucide.createIcons && !root.getAttribute('data-icons-done')) {
    try { window.lucide.createIcons(); } catch (e) { /* abaikan */ }
    root.setAttribute('data-icons-done', '1');
  }

  setConn('Menghubungkan…', 'idle');

  var opts = {
    clientId: 'web_lvmdp_' + Math.random().toString(16).slice(2, 10),
    clean: true,
    keepalive: 30,
    reconnectPeriod: 2000,
    connectTimeout: 10000
  };
  if (user !== '') opts.username = user;
  if (pass !== '') opts.password = pass;

  var client;
  try {
    client = window.mqtt.connect(wsUrl, opts);
  } catch (e) {
    setConn('Error koneksi', 'idle');
    return;
  }

  client.on('connect', function () {
    setConn('Terhubung — menunggu data…', 'idle');
    try {
      client.subscribe(topic, { qos: 0 });
    } catch (e) { /* abaikan */ }
  });
  client.on('reconnect', function () { setConn('Menyambung ulang…', 'idle'); });
  client.on('close', function () { setConn('Terputus dari broker', 'idle'); });
  client.on('error', function () { setConn('Error koneksi', 'idle'); });

  client.on('message', function (recvTopic, payload) {
    if (!isOurTopic(recvTopic)) return;
    var text = '';
    try {
      text = payload ? payload.toString() : '';
    } catch (e) {
      return;
    }
    if (text.length > 8192) return;
    var d;
    try {
      d = JSON.parse(text);
    } catch (e) {
      return;
    }
    render(d);
  });
})();
