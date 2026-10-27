/* T-T-H hover interaktif: grouped bar chart "Total Tarif Harian"
   — crosshair + tooltip + bar dimming + x-label highlight.
   Mengikuti pola yang sama dgn lvmdp-trend.js (pointermove, pointerleave,
   keyboard arrow). Tanpa lib, vanilla JS. */
(function () {
  'use strict';

  var card  = document.getElementById('tarif7-card');
  var chart = document.getElementById('tarif7-chart');
  var hx    = document.getElementById('tarif7-hx');
  var tip   = document.getElementById('tarif7-tip');
  var srcEl = document.getElementById('tarif7-data');

  if (!card || !chart || !srcEl) return;

  var tdata = null;
  try { tdata = JSON.parse(srcEl.textContent || '{}'); } catch (e) { return; }
  if (!tdata.days || !tdata.days.length) return;

  var n     = tdata.n;
  var days  = tdata.days;
  var ymax  = tdata.ymax;
  var activeDay = -1;

  /* format Rp Indonesia (tanpa desimal) */
  function fmtRp(v) {
    var s = Math.round(v).toString();
    var out = '';
    for (var i = s.length - 1, c = 0; i >= 0; i--, c++) {
      if (c > 0 && c % 3 === 0) out = '.' + out;
      out = s[i] + out;
    }
    return out;
  }

  /* render tooltip content untuk hari ke-idx */
  function renderTip(idx) {
    if (!tip) return;
    var d = days[idx];
    /* Clear */
    while (tip.firstChild) tip.removeChild(tip.firstChild);

    /* Header: tanggal */
    var head = document.createElement('span');
    head.className = 'font-semibold text-xs';
    head.textContent = d.lbl;
    tip.appendChild(head);

    /* Rows: LWBP, WBP, Total */
    var items = [
      { label: 'LWBP',  value: d.lwbp,  color: 'var(--ems-steel-400)' },
      { label: 'WBP',   value: d.wbp,   color: 'var(--ems-amber)' },
      { label: 'Total', value: d.total,  color: 'var(--ems-ink)' }
    ];
    for (var i = 0; i < items.length; i++) {
      var row = document.createElement('div');
      row.className = 'flex items-center gap-2';
      if (i === 2) row.style.borderTop = '1px solid var(--ems-line)';
      if (i === 2) row.style.paddingTop = '3px';
      if (i === 2) row.style.marginTop = '2px';

      var dot = document.createElement('span');
      dot.className = 'size-2 shrink-0 rounded-sm';
      dot.style.background = items[i].color;
      row.appendChild(dot);

      var lbl = document.createElement('span');
      lbl.className = 'text-ems-muted min-w-[32px]';
      lbl.textContent = items[i].label;
      row.appendChild(lbl);

      var val = document.createElement('span');
      val.className = 'font-medium ml-auto';
      if (i === 2) val.className += ' font-semibold';
      val.textContent = 'Rp ' + fmtRp(items[i].value);
      row.appendChild(val);

      tip.appendChild(row);
    }
  }

  function showHover(idx) {
    if (idx < 0 || idx >= n) return;
    if (idx === activeDay) return;
    activeDay = idx;

    chart.classList.add('has-hover');

    /* bars: dim all, activate matching day */
    var bars = chart.querySelectorAll('.tarif7-bar');
    for (var b = 0; b < bars.length; b++) {
      var bd = parseInt(bars[b].getAttribute('data-day'), 10);
      if (bd === idx) {
        bars[b].classList.add('is-active');
      } else {
        bars[b].classList.remove('is-active');
      }
    }

    /* x-axis label highlight */
    var xlabels = card.querySelectorAll('.tarif7-xlabel');
    for (var x = 0; x < xlabels.length; x++) {
      var xi = parseInt(xlabels[x].getAttribute('data-xlabel'), 10);
      if (xi === idx) {
        xlabels[x].classList.add('is-active');
      } else {
        xlabels[x].classList.remove('is-active');
      }
    }

    /* crosshair: center of the day slot */
    if (hx) {
      hx.classList.remove('hidden');
      var pct = ((idx + 0.5) / n * 100);
      hx.style.left = pct + '%';
    }

    /* tooltip */
    renderTip(idx);
    if (tip) {
      tip.classList.remove('hidden');
      /* position: top of the chart area, horizontally at day center */
      tip.style.top = '8px';
      var leftPct = Math.min(Math.max((idx + 0.5) / n * 100, 12), 88);
      tip.style.left = leftPct + '%';

      /* show with a slight delay for the transition to kick in */
      requestAnimationFrame(function () {
        tip.classList.add('is-shown');
      });
    }
  }

  function hideHover() {
    if (activeDay === -1) return;
    activeDay = -1;

    chart.classList.remove('has-hover');

    /* remove active state from all bars */
    var bars = chart.querySelectorAll('.tarif7-bar');
    for (var b = 0; b < bars.length; b++) {
      bars[b].classList.remove('is-active');
    }

    /* remove active x-axis labels */
    var xlabels = card.querySelectorAll('.tarif7-xlabel');
    for (var x = 0; x < xlabels.length; x++) {
      xlabels[x].classList.remove('is-active');
    }

    if (hx) hx.classList.add('hidden');
    if (tip) {
      tip.classList.remove('is-shown');
      /* after the fade-out transition completes, hide element */
      setTimeout(function () {
        if (activeDay === -1) tip.classList.add('hidden');
      }, 200);
    }
  }

  /* pointermove on the chart area — determine which day slot from x position */
  function onChartMove(ev) {
    var rect = chart.getBoundingClientRect();
    var f = (ev.clientX - rect.left) / rect.width;
    f = Math.max(0, Math.min(1, f));
    var idx = Math.min(Math.floor(f * n), n - 1);
    showHover(idx);
  }
  chart.addEventListener('pointermove', onChartMove);
  chart.addEventListener('mousemove', onChartMove);  /* fallback */

  chart.addEventListener('pointerleave', function () { hideHover(); });
  chart.addEventListener('mouseleave', function () { hideHover(); });

  /* keyboard navigation (arrows, home, end, esc) — matches lvmdp-trend.js */
  chart.addEventListener('keydown', function (ev) {
    var now = activeDay;
    if (now === -1) now = 0;

    if (ev.key === 'ArrowRight') now = Math.min(now + 1, n - 1);
    else if (ev.key === 'ArrowLeft') now = Math.max(now - 1, 0);
    else if (ev.key === 'Home') now = 0;
    else if (ev.key === 'End') now = n - 1;
    else if (ev.key === 'Escape') { hideHover(); ev.preventDefault(); return; }
    else return;

    ev.preventDefault();
    /* force re-render even if same idx */
    activeDay = -1;
    showHover(now);
  });

  chart.addEventListener('focusout', function () {
    hideHover();
  });
})();
