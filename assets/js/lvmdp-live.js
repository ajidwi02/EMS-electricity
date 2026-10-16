/* EMS LVMDP live: MQTT over WebSocket, display saja (tanpa DB). */
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

  function render(d) {
    if (typeof d !== 'object' || d === null) return;
    if (d._groupName !== 'lvmdp') return;
    var i;
    for (i = 0; i < fields.length; i++) {
      (function (el) {
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
