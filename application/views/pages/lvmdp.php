<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Slot gauge LVMDP (G1): tiap card 3 unit. (f = field MQTT, label tampil, aria untuk screen reader.)
// Zona + jarum digambar JS dari 1 tabel (plans/ems-lvmdp-gauge-charts.md §2.3). Tanpa JS: slot kosong + angka '-'.
$ln_gauges = array(
  array('f' => 'v_r', 'label' => 'V-R Fasa-Netral', 'aria' => 'Gauge V-R'),
  array('f' => 'v_s', 'label' => 'V-S Fasa-Netral', 'aria' => 'Gauge V-S'),
  array('f' => 'v_t', 'label' => 'V-T Fasa-Netral', 'aria' => 'Gauge V-T'),
);
$ll_gauges = array(
  array('f' => 'v_rs', 'label' => 'V-RS Antar-Fasa', 'aria' => 'Gauge V-RS'),
  array('f' => 'v_st', 'label' => 'V-ST Antar-Fasa', 'aria' => 'Gauge V-ST'),
  array('f' => 'v_rt', 'label' => 'V-RT Antar-Fasa', 'aria' => 'Gauge V-RT'),
);
$a_gauges = array(
  array('f' => 'i_r', 'label' => 'I-R', 'aria' => 'Gauge I-R'),
  array('f' => 'i_s', 'label' => 'I-S', 'aria' => 'Gauge I-S'),
  array('f' => 'i_t', 'label' => 'I-T', 'aria' => 'Gauge I-T'),
);

// ── L-T/P-H fase 2: skala & refs & titik TERCATAT dari payload (kontrak SS2-D6
// plans/ems-lvmdp-db-trend.md; amandemen CP-049: skala adaptif). $day_line:
// pts [menit, nilai] -> polyline 800x300 (sumbu tetap 00:00–24:00: x = menit/1440*800;
// y skala EFEKTIF dari chart payload — titik di luar skala tidak dipotong lagi).
$day_line = function ($pts, $ymin, $ymax) {
  $out = '';
  foreach ((array) $pts as $p) {
    if (count($p) < 2) { continue; }
    $m = (float) $p[0];
    $v = (float) $p[1];
    $out .= sprintf('%.2F,%.2F ', ($m / 1440.0) * 800.0,
      300.0 - 300.0 * (($v - $ymin) / ($ymax - $ymin)));
  }
  return rtrim($out);
};
// CP-049: null = '—' (bukan '-'); nilai di luar batas dasar skala diberi panah
// ↑/↓ supaya tidak tertukar dengan data normal saat skala meluas.
$dash_num = function ($v, $dec) { return ($v === NULL || $v === '') ? '—' : number_format((float) $v, $dec, '.', ''); };
$trend_val = function ($v, $dec, $sc) use ($dash_num) {
  $num = $dash_num($v, $dec);
  if ($num === '—' || !isset($sc['limit'])) { return $num; }
  $lim  = (float) $sc['limit'];
  $base = isset($sc['base_min']) ? (float) $sc['base_min'] : $lim;
  if ((float) $v > $lim + 0.0001)  { return $num . ' ↑'; }
  if ((float) $v < $base - 0.0001) { return $num . ' ↓'; }
  return $num;
};
$trend_style = array(
  'R' => array('var' => 'var(--ems-fasa-r)', 'dash' => ''),
  'S' => array('var' => 'var(--ems-fasa-s)', 'dash' => '10 4'),
  'T' => array('var' => 'var(--ems-ink)',    'dash' => '2 4'),
);
$trend_num = function ($v, $dec = 1) {
  return number_format((float) $v, $dec, '.', '');
};

// ── K-T fase 1: kontrak kartu "Konsumsi & Tarif Hari Kemarin" — spec §2.3 plans/ems-lvmdp-tarif-kemarin.md ──
// Format angka ledger: comma-thousands + dot-decimal (D3) — kWh 2 des, Rp 0 des.
$tarif_num = function ($v, $dec) {
  return number_format((float) $v, $dec, '.', ',');
};
?>
<div id="lvmdp-root"
  data-ws="<?php echo html_escape(isset($mqtt_ws_url) ? $mqtt_ws_url : 'ws://localhost:8083/mqtt'); ?>"
  data-topic="<?php echo html_escape(isset($mqtt_topic) ? $mqtt_topic : 'data/ems/lvmdp/+'); ?>"
  data-user="<?php echo html_escape(isset($mqtt_user) ? $mqtt_user : ''); ?>"
  data-pass="<?php echo html_escape(isset($mqtt_pass) ? $mqtt_pass : ''); ?>"
  data-refresh="<?php echo html_escape(site_url('lvmdp/trend')); ?>"
  class="flex flex-col gap-6">

  <details class="group rounded-lg border border-ems-line bg-white">
    <summary class="flex list-none cursor-pointer select-none items-center gap-3 px-4 py-3 md:px-6">
      <span id="lvmdp-dot" class="size-2 shrink-0 rounded-full bg-ems-muted"></span>
      <h2 class="truncate text-sm font-semibold">Status Koneksi</h2>
      <span id="lvmdp-conn" class="ml-auto min-w-0 truncate text-xs text-ems-muted">Menghubungkan…</span>
      <i data-lucide="chevron-down" class="size-4 shrink-0 text-ems-muted group-open:rotate-180"></i>
    </summary>
    <div class="flex flex-col gap-3 border-t border-ems-line px-4 py-4 md:flex-row md:items-center md:gap-6 md:px-6">
      <div class="flex min-w-0 flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wider text-ems-muted">Terakhir update</span>
        <span id="lvmdp-updated" class="truncate text-sm font-semibold tabular-nums">-</span>
      </div>
      <div class="flex min-w-0 flex-col gap-1">
        <span class="text-[11px] font-medium uppercase tracking-wider text-ems-muted">Waktu perangkat</span>
        <span id="lvmdp-devtime" class="truncate text-sm font-semibold tabular-nums">-</span>
      </div>
      <div class="flex min-w-0 flex-col gap-1 md:ml-auto">
        <span class="text-[11px] font-medium uppercase tracking-wider text-ems-muted">Topic</span>
        <span class="truncate text-xs text-ems-muted">data/ems/lvmdp/+</span>
      </div>
    </div>
  </details>

  <div class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:gap-3 md:gap-4">
    <div class="flex min-w-0 flex-col gap-3 rounded-lg border border-ems-line bg-white p-4 sm:p-3 md:p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="zap" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Tegangan L-N</span>
      </div>
      <div class="flex flex-col gap-5">
        <?php foreach ($ln_gauges as $g): ?>
        <div class="flex items-center gap-4 sm:gap-2 md:gap-3 xl:gap-4">
          <svg class="gauge size-28 shrink-0 sm:size-20 xl:size-28" viewBox="0 0 120 70" role="img" aria-label="<?php echo html_escape($g['aria']); ?>" data-gauge="ln" data-f="<?php echo html_escape($g['f']); ?>"></svg>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-xs font-medium uppercase leading-tight tracking-wider text-ems-muted"><?php echo html_escape($g['label']); ?></span>
            <span class="truncate text-xl font-semibold tabular-nums"><span data-f="<?php echo html_escape($g['f']); ?>" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
            <span class="flex items-center gap-2">
              <svg class="size-2 shrink-0" viewBox="0 0 8 8" aria-hidden="true"><circle id="dot-<?php echo html_escape($g['f']); ?>" cx="4" cy="4" r="4" fill="#9AA1AD"/></svg>
              <span id="gst-<?php echo html_escape($g['f']); ?>" class="truncate text-xs text-ems-muted">-</span>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex min-w-0 flex-col gap-3 rounded-lg border border-ems-line bg-white p-4 sm:p-3 md:p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="activity" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Tegangan L-L</span>
      </div>
      <div class="flex flex-col gap-5">
        <?php foreach ($ll_gauges as $g): ?>
        <div class="flex items-center gap-4 sm:gap-2 md:gap-3 xl:gap-4">
          <svg class="gauge size-28 shrink-0 sm:size-20 xl:size-28" viewBox="0 0 120 70" role="img" aria-label="<?php echo html_escape($g['aria']); ?>" data-gauge="ll" data-f="<?php echo html_escape($g['f']); ?>"></svg>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-xs font-medium uppercase leading-tight tracking-wider text-ems-muted"><?php echo html_escape($g['label']); ?></span>
            <span class="truncate text-xl font-semibold tabular-nums"><span data-f="<?php echo html_escape($g['f']); ?>" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
            <span class="flex items-center gap-2">
              <svg class="size-2 shrink-0" viewBox="0 0 8 8" aria-hidden="true"><circle id="dot-<?php echo html_escape($g['f']); ?>" cx="4" cy="4" r="4" fill="#9AA1AD"/></svg>
              <span id="gst-<?php echo html_escape($g['f']); ?>" class="truncate text-xs text-ems-muted">-</span>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="flex min-w-0 flex-col gap-3 rounded-lg border border-ems-line bg-white p-4 sm:p-3 md:p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="plug-zap" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Arus</span>
      </div>
      <div class="flex flex-col gap-5">
        <?php foreach ($a_gauges as $g): ?>
        <div class="flex items-center gap-4 sm:gap-2 md:gap-3 xl:gap-4">
          <svg class="gauge size-28 shrink-0 sm:size-20 xl:size-28" viewBox="0 0 120 70" role="img" aria-label="<?php echo html_escape($g['aria']); ?>" data-gauge="a" data-f="<?php echo html_escape($g['f']); ?>"></svg>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="text-xs font-medium uppercase leading-tight tracking-wider text-ems-muted"><?php echo html_escape($g['label']); ?></span>
            <span class="truncate text-xl font-semibold tabular-nums"><span data-f="<?php echo html_escape($g['f']); ?>" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">A</span></span>
            <span class="flex items-center gap-2">
              <svg class="size-2 shrink-0" viewBox="0 0 8 8" aria-hidden="true"><circle id="dot-<?php echo html_escape($g['f']); ?>" cx="4" cy="4" r="4" fill="#9AA1AD"/></svg>
              <span id="gst-<?php echo html_escape($g['f']); ?>" class="truncate text-xs text-ems-muted">-</span>
            </span>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="rounded-lg border border-ems-line bg-white p-4">
    <div class="flex items-center gap-2">
      <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
        <i data-lucide="gauge" class="size-4 text-ems-muted"></i>
      </span>
      <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Daya &amp; Energi</span>
    </div>
    <div class="mt-3 grid grid-cols-2 gap-4 sm:grid-cols-4">
      <div class="flex min-w-0 flex-col gap-1">
        <span class="text-xs text-ems-muted">PF</span>
        <span class="truncate text-xl font-semibold tabular-nums"><span data-f="pf" data-dec="2">-</span></span>
      </div>
      <div class="flex min-w-0 flex-col gap-1">
        <span class="text-xs text-ems-muted">kW</span>
        <span class="truncate text-xl font-semibold tabular-nums"><span data-f="kw" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">kW</span></span>
      </div>
      <div class="flex min-w-0 flex-col gap-1">
        <span class="text-xs text-ems-muted">kWh</span>
        <span class="truncate text-xl font-semibold tabular-nums"><span data-f="Kwh" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">kWh</span></span>
      </div>
      <div class="flex min-w-0 flex-col gap-1">
        <span class="text-xs text-ems-muted">Freq (Hz)</span>
        <span class="truncate text-xl font-semibold tabular-nums"><span data-f="Hz" data-dec="2">-</span></span>
      </div>
    </div>
  </div>

  <!-- K-T: kartu "Konsumsi & Tarif Hari Kemarin" — data DB dinamis (Ems::tarif_kemarin_db, CP-050;
       fallback dummy saat config/panel/data kosong; badge dinamis CP-051) -->
  <?php $kt_db = (isset($tarif['src']) && $tarif['src'] === 'db'); ?>
  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:items-center md:px-6">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="receipt" class="size-4 text-ems-muted"></i>
        </span>
        <div class="flex min-w-0 flex-col">
          <h2 class="truncate text-sm font-semibold">Konsumsi &amp; Tarif Hari Kemarin</h2>
          <p class="truncate text-xs text-ems-muted">Pembagian kWh &amp; biaya energi per blok tarif PLN</p>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-2 sm:ml-auto">
        <span class="truncate text-xs tabular-nums text-ems-muted"><?= html_escape($tarif['tgl']) ?></span>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold <?= $kt_db ? 'bg-ems-ok/10 text-ems-ok' : 'bg-ems-ambersoft text-ems-amberdeep' ?>"><?= $kt_db ? 'Sumber DB' : 'Data dummy' ?></span>
      </div>
    </div>

    <div class="px-4 py-4 md:px-6">

      <div role="img" aria-label="Peta blok tarif 24 jam WIB: LWBP 2 22:00–06:00 · LWBP 1 06:00–17:00 · WBP 17:00–22:00" class="overflow-hidden rounded-md border border-ems-line">
        <div aria-hidden="true" class="flex h-7">
          <div class="flex min-w-0 items-center justify-center bg-ems-bg" style="width:25.00%"><span class="truncate px-1 text-[10px] font-medium text-ems-muted">LWBP 2</span></div>
          <div class="flex min-w-0 items-center justify-center bg-ems-bg" style="width:45.83%"><span class="truncate px-1 text-[10px] font-medium text-ems-muted">LWBP 1</span></div>
          <div class="flex min-w-0 items-center justify-center bg-ems-ambersoft" style="width:20.83%"><span class="truncate px-1 text-[10px] font-semibold text-ems-amberdeep">WBP</span></div>
          <div class="min-w-0 flex-1 bg-ems-bg"></div>
        </div>
        <div aria-hidden="true" class="relative mt-1 h-4">
          <span class="absolute left-0 top-0 leading-4 text-[10px] tabular-nums text-ems-muted">00:00</span>
          <span class="absolute top-0 -translate-x-1/2 leading-4 text-[10px] tabular-nums text-ems-muted" style="left:25.00%">06:00</span>
          <span class="absolute top-0 -translate-x-1/2 leading-4 text-[10px] tabular-nums text-ems-muted" style="left:70.83%">17:00</span>
          <span class="absolute top-0 -translate-x-full leading-4 text-[10px] tabular-nums text-ems-muted" style="left:91.67%">22:00</span>
          <span class="absolute right-0 top-0 leading-4 text-[10px] tabular-nums text-ems-muted">24:00</span>
        </div>
      </div>

      <table class="mt-4 w-full table-fixed">
        <colgroup>
          <col style="width:36%"><col style="width:26%"><col style="width:38%">
        </colgroup>
        <caption class="sr-only">Rincian konsumsi kWh dan estimasi tarif per blok PLN untuk hari kemarin (WIB), <?= $kt_db ? 'sumber DB (data_electricity_iot5)' : 'data dummy' ?></caption>
        <thead>
          <tr class="border-b border-ems-line/70 text-[10px] font-medium uppercase tracking-wider text-ems-muted">
            <th scope="col" class="py-1.5 pr-2 text-left">Blok tarif</th>
            <th scope="col" class="py-1.5 px-2 text-right">kWh</th>
            <th scope="col" class="py-1.5 pl-2 text-right">Tarif (Rp)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($tarif['rows'] as $r): $st = ($r['t'] === 'subtotal'); ?>
          <tr class="border-b border-ems-line/50 text-[13px] leading-6">
            <th scope="row" class="py-2 pr-2 text-left font-<?= $st ? 'medium' : 'normal'; ?>">
              <span class="truncate"><?= html_escape($r['label']) ?></span>
              <span class="tabular-nums text-ems-muted text-[10px]">· <?= html_escape($r['win']) ?></span>
            </th>
            <td class="px-2 py-2 text-right tabular-nums">
              <span class="<?= $st ? 'font-medium' : 'font-normal'; ?> text-ems-ink"><?= $tarif_num($r['kwh'], 2) ?></span><span class="text-[10px] text-ems-muted hidden sm:inline"> kWh</span>
            </td>
            <td class="pl-2 py-2 text-right tabular-nums">
              <span class="<?= $st ? 'font-medium' : 'font-normal'; ?> text-ems-ink"><?= $tarif_num($r['biaya'], 0) ?></span><span class="text-[10px] text-ems-muted hidden sm:inline"> Rp</span>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr class="border-t border-t-2 border-ems-line text-[13px] leading-6">
            <th scope="row" class="py-2.5 pr-2 text-left font-semibold text-ems-ink">
              TOTAL
              <span class="block tabular-nums text-ems-muted text-[10px] font-normal">satu hari (WIB)</span>
            </th>
            <td class="px-2 py-2.5 text-right tabular-nums">
              <span class="font-semibold text-ems-ink"><?= $tarif_num($tarif['kwh_total'], 2) ?></span><span class="text-[10px] text-ems-muted hidden sm:inline"> kWh</span>
            </td>
            <td class="pl-2 py-2.5 text-right tabular-nums">
              <span class="font-semibold text-ems-ink">Rp <?= $tarif_num($tarif['biaya_total'], 0) ?></span><span class="text-[10px] text-ems-muted hidden sm:inline"> Rp</span>
            </td>
          </tr>
        </tfoot>
      </table>

      <p class="mt-3 text-[11px] leading-4 text-ems-muted">
        Tarif LWBP Rp <?= $tarif_num($tarif['rate_l'], 2) ?>/kWh · WBP = <?= html_escape($tarif['k']) ?>× (Rp <?= $tarif_num($tarif['rate_l'] * $tarif['k'], 2) ?>/kWh) · Permen ESDM 7/2024, gol. I-3/TM · Estimasi biaya energi harian — belum termasuk RM, kVArh, PPN, PPJ, materai · <?= $kt_db ? 'Sumber: DB data_electricity_iot5 (WIB)' : 'Data dummy — fallback' ?>.
      </p>
    </div>
  </div>

  <!-- T-T-H: kartu "Total Tarif Harian" — grouped bar chart DB dinamis (Ems::tarif7_harian_db, CP-050;
       fallback dummy saat config/panel/data kosong; badge dinamis CP-051) -->
  <?php $t7_db = (isset($tarif7['src']) && $tarif7['src'] === 'db'); ?>
  <div class="rounded-lg border border-ems-line bg-white" id="tarif7-card">
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:items-center md:px-6">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="bar-chart-3" class="size-4 text-ems-muted"></i>
        </span>
        <div class="flex min-w-0 flex-col">
          <h2 class="truncate text-sm font-semibold">Total Tarif Harian</h2>
          <p class="truncate text-xs text-ems-muted">Tarif harian 7 hari terakhir — LWBP, WBP &amp; Total</p>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-2 sm:ml-auto">
        <span class="truncate text-xs tabular-nums text-ems-muted"><?= html_escape($tarif7['tgl']) ?></span>
        <span class="shrink-0 rounded-full px-2.5 py-1 text-[11px] font-semibold <?= $t7_db ? 'bg-ems-ok/10 text-ems-ok' : 'bg-ems-ambersoft text-ems-amberdeep' ?>"><?= $t7_db ? 'Sumber DB' : 'Data dummy' ?></span>
      </div>
    </div>

    <div class="px-4 py-4 md:px-6">

      <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
        <span class="flex items-center gap-1.5"><span class="size-2.5 shrink-0 rounded-sm" style="background:var(--ems-steel-400)"></span><span class="text-[10px] font-medium text-ems-muted">LWBP</span></span>
        <span class="flex items-center gap-1.5"><span class="size-2.5 shrink-0 rounded-sm" style="background:var(--ems-amber)"></span><span class="text-[10px] font-medium text-ems-muted">WBP</span></span>
        <span class="flex items-center gap-1.5"><span class="size-2.5 shrink-0 rounded-sm" style="background:var(--ems-ink)"></span><span class="text-[10px] font-medium text-ems-muted">Total</span></span>
      </div>

      <?php $n7  = count($tarif7['days']);
            $slot = 800 / $n7;
            $b_w  = 20.0;
            $b_g  = 6.0;
            $b_o  = ($slot - (3 * $b_w + 2 * $b_g)) / 2; ?>
      <div class="mt-2 flex">
        <div class="relative w-12 shrink-0 h-40 sm:h-52 lg:h-60">
          <?php for ($yv = $tarif7['ymin']; $yv <= $tarif7['ymax'] + 0.001; $yv += $tarif7['ystep']):
            $frac = ($tarif7['ymax'] - $yv) / ($tarif7['ymax'] - $tarif7['ymin']); ?>
          <span class="absolute right-2 -translate-y-1/2 text-[10px] tabular-nums text-ems-muted" style="top:<?= sprintf('%.2F%%', $frac * 100) ?>"><?= ((int) $yv === 0) ? '0' : number_format($yv / 1000000.0, 0, '.', '') . ' jt' ?></span>
          <?php endfor; ?>
        </div>
        <div class="relative min-w-0 flex-1 cursor-crosshair h-40 sm:h-52 lg:h-60" id="tarif7-chart" tabindex="0" role="img" aria-label="Tarif harian (Rp) 7 hari terakhir WIB, <?= $t7_db ? 'sumber DB' : 'data dummy' ?>. Arahkan kursor untuk detail.">
          <svg class="block size-full" viewBox="0 0 800 300" preserveAspectRatio="none" aria-hidden="true">
            <?php for ($yv = $tarif7['ymin']; $yv <= $tarif7['ymax'] + 0.001; $yv += $tarif7['ystep']):
              $gy = 300 - 300 * (($yv - $tarif7['ymin']) / ($tarif7['ymax'] - $tarif7['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $gy) ?>" x2="800" y2="<?= sprintf('%.2F', $gy) ?>" stroke="#E3E5E0" stroke-width="1" vector-effect="non-scaling-stroke" />
            <?php endfor; ?>
            <?php foreach ($tarif7['days'] as $i => $d):
              $xl = $i * $slot + $b_o;
              $x1 = $xl;
              $x2 = $xl + $b_w + $b_g;
              $x3 = $xl + 2 * ($b_w + $b_g); ?>
            <rect data-bar="lwbp" data-day="<?= $i ?>" x="<?= sprintf('%.2F', $x1) ?>" y="<?= sprintf('%.2F', 300 - 300 * $d['lwbp'] / $tarif7['ymax']) ?>" width="<?= sprintf('%.2F', $b_w) ?>" height="<?= sprintf('%.2F', 300 * $d['lwbp'] / $tarif7['ymax']) ?>" fill="var(--ems-steel-400)" class="tarif7-bar" />
            <rect data-bar="wbp" data-day="<?= $i ?>" x="<?= sprintf('%.2F', $x2) ?>" y="<?= sprintf('%.2F', 300 - 300 * $d['wbp'] / $tarif7['ymax']) ?>" width="<?= sprintf('%.2F', $b_w) ?>" height="<?= sprintf('%.2F', 300 * $d['wbp'] / $tarif7['ymax']) ?>" fill="var(--ems-amber)" class="tarif7-bar" />
            <rect data-bar="total" data-day="<?= $i ?>" x="<?= sprintf('%.2F', $x3) ?>" y="<?= sprintf('%.2F', 300 - 300 * $d['total'] / $tarif7['ymax']) ?>" width="<?= sprintf('%.2F', $b_w) ?>" height="<?= sprintf('%.2F', 300 * $d['total'] / $tarif7['ymax']) ?>" fill="var(--ems-ink)" class="tarif7-bar" />
            <?php endforeach; ?>
            <?php /* Invisible hover zones per day — full slot width × full height */ ?>
            <?php foreach ($tarif7['days'] as $i => $d): ?>
            <rect data-zone="<?= $i ?>" x="<?= sprintf('%.2F', $i * $slot) ?>" y="0" width="<?= sprintf('%.2F', $slot) ?>" height="300" fill="transparent" class="tarif7-zone" />
            <?php endforeach; ?>
          </svg>
          <!-- crosshair vertical line -->
          <div id="tarif7-hx" class="pointer-events-none absolute inset-y-0 hidden w-px bg-ems-steel-400/60" style="transition:left .12s cubic-bezier(.22,1,.36,1)"></div>
          <!-- tooltip -->
          <div id="tarif7-tip" class="pointer-events-none absolute z-10 hidden min-w-max rounded-md border border-ems-line bg-white px-3 py-2.5 text-[11px] leading-5 tabular-nums text-ems-ink" style="box-shadow:0 4px 16px rgba(0,0,0,.10);transition:opacity .18s ease,transform .18s cubic-bezier(.22,1,.36,1)"></div>
        </div>
      </div>

      <div class="mt-1 flex">
        <div class="w-12 shrink-0"></div>
        <div class="relative min-w-0 flex-1 h-4">
          <?php foreach ($tarif7['days'] as $i => $d): ?>
          <span data-xlabel="<?= $i ?>" class="tarif7-xlabel absolute top-0 -translate-x-1/2 text-[10px] tabular-nums text-ems-muted" style="left:<?= sprintf('%.2F%%', ($i + 0.5) * 100 / $n7) ?>"><?= html_escape($d['lbl']) ?></span>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="mt-3 grid grid-cols-3 gap-2 sm:gap-4">
        <?php $t7 = $tarif7['stat']; foreach (array('max' => 'Tertinggi', 'avg' => 'Rata-rata', 'min' => 'Terendah') as $fld => $tl): ?>
        <div class="min-w-0">
          <span class="block text-[10px] text-ems-muted"><?= $tl ?></span>
          <span class="block truncate tabular-nums text-ems-ink">
            <span class="text-[11px] font-normal text-ems-muted">Rp </span><span class="font-semibold"><?= $tarif_num($t7[$fld], 0) ?></span>
            <?php if ($fld !== 'avg'): ?>
            <span class="text-[10px] font-normal text-ems-muted"><?= html_escape($t7['r' . $fld]) ?></span>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p class="border-t border-ems-line px-4 py-3 text-[11px] text-ems-muted md:px-6">
      Bar Total = bar LWBP + bar WBP · Skala Rp 0–<?= number_format($tarif7['ymax'] / 1000000.0, 0, '.', '') ?> jt · Total 7 hari Rp <?= $tarif_num($tarif7['sum'], 0) ?> · Estimasi biaya energi — belum termasuk RM, kVArh, PPN, PPJ, materai · <?= $t7_db ? 'Sumber: DB data_electricity_iot5 (WIB)' : 'Data dummy — fallback' ?>.
    </p>
    <!-- T-T-H: data JSON untuk hover interaktif -->
    <script type="application/json" id="tarif7-data"><?= json_encode(array(
      'days' => array_values($tarif7['days']),
      'ymax' => $tarif7['ymax'],
      'n'    => count($tarif7['days']),
    ), JSON_UNESCAPED_UNICODE) ?></script>
  </div>

  <!-- L-T fase 2: kartu Tren 24 Jam (DB ems.data_electricity_iot5, dinamis, hover interaktif — SS5-T4) -->
  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:items-center md:px-6">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="trending-up" class="size-4 text-ems-muted"></i>
        </span>
        <div class="flex min-w-0 flex-col">
          <h2 class="truncate text-sm font-semibold">Tren 24 Jam</h2>
          <p class="truncate text-xs text-ems-muted">Tegangan &amp; arus hari ini — pola beban harian</p>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-2 sm:ml-auto">
        <span data-date="lt" class="truncate text-xs tabular-nums text-ems-muted"><?= html_escape($trends['tgl']) ?></span>
        <span class="shrink-0 rounded-full bg-ems-ok/10 px-2.5 py-1 text-[11px] font-semibold text-ems-ok">Sumber DB</span>
      </div>
    </div>

    <?php $ci = 0; foreach ($trends['charts'] as $tkey => $t_c):
      $sc     = $t_c['scale'];
      $plot_h = 'h-40 sm:h-52 lg:h-60';
      $ci++;
      $is_last  = ($ci === 3);
      $is_first = ($ci === 1);
      $pad      = $is_first ? 'pt-3 pb-4' : ($is_last ? 'pt-4 pb-3' : 'pt-4 pb-4');
    ?>
    <div class="<?php echo $is_last ? '' : 'border-b border-ems-line/70 '; ?><?php echo $pad; ?> px-4 md:px-6">
      <h3 class="flex min-w-0 flex-wrap items-baseline gap-x-2 gap-y-1" data-trendhead="<?= html_escape($tkey) ?>">
        <span class="truncate text-sm font-semibold"><?= html_escape($t_c['title']) ?></span>
        <span class="shrink-0 text-xs font-normal text-ems-muted"><?= html_escape($t_c['unit']) ?></span>
        <?php if (!empty($sc['expanded'])): ?>
        <span data-scaleinfo class="ml-auto shrink-0 text-[11px] tabular-nums text-ems-muted">Skala <?= (int) $sc['ymin'] ?>–<?= (int) $sc['ymax'] ?> <?= ($tkey === 'a') ? 'A' : 'V' ?> · <span class="text-ems-amberdeep">batas <?= (int) $sc['limit'] ?></span></span>
        <?php endif; ?>
      </h3>

      <div class="mt-2 grid grid-cols-4 gap-x-3 text-xs" role="table" aria-label="Ringkasan <?= html_escape($t_c['title']) ?>">
        <div></div>
        <?php foreach (array('R', 'S', 'T') as $sk): ?>
        <div class="flex items-center gap-1.5 font-semibold">
          <span class="size-2 shrink-0 rounded-full" style="background:<?php echo $trend_style[$sk]['var']; ?>"></span>
          <?= $sk ?>
        </div>
        <?php endforeach; ?>
        <?php foreach (array('max' => 'Maks', 'avg' => 'Rata', 'min' => 'Min') as $fld => $lbl): ?>
        <div class="truncate text-ems-muted"><?= $lbl ?></div>
        <?php foreach (array('R', 'S', 'T') as $sk): $st = $t_c['series'][$sk]['stat']; $dec = ($tkey === 'a') ? 0 : 1; ?>
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-1 tabular-nums">
          <span data-cell="<?= html_escape($tkey . ':' . $sk . ':' . $fld) ?>"><?= $trend_val($st[$fld], $dec, $sc) ?> <?= ($tkey === 'a') ? 'A' : 'V' ?></span>
          <?php if ($fld !== 'avg'): ?>
          <span class="text-[10px] text-ems-muted"><?= html_escape($st['r' . $fld]) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>

      <div class="mt-3 flex">
        <div data-yaxis="<?= html_escape($tkey) ?>" class="relative w-12 shrink-0 <?= $plot_h ?>">
          <?php for ($yv = $sc['ymin']; $yv <= $sc['ymax'] + 0.001; $yv += $sc['ystep']):
            $frac = ($sc['ymax'] - $yv) / ($sc['ymax'] - $sc['ymin']); ?>
          <span class="absolute right-2 -translate-y-1/2 text-[10px] tabular-nums text-ems-muted" style="top:<?= sprintf('%.2F%%', $frac * 100) ?>"><?= (int) $yv ?></span>
          <?php endfor; ?>
        </div>
        <div class="relative min-w-0 flex-1 cursor-crosshair rounded-md <?= $plot_h ?> outline-offset-2 focus-visible:outline-2 focus-visible:outline-ems-amber" tabindex="0" role="img" data-trend="<?= html_escape($tkey) ?>" aria-label="<?= html_escape($t_c['title']) ?> hari ini WIB, dari pencatatan DB 00:00–24:00">
          <svg class="block size-full" viewBox="0 0 800 300" preserveAspectRatio="none" aria-hidden="true">
            <rect x="600" y="0" width="133.33" height="300" fill="rgba(234,165,0,0.10)" />
            <g data-deco>
            <?php for ($yv = $sc['ymin']; $yv <= $sc['ymax'] + 0.001; $yv += $sc['ystep']):
              $gy = 300 - 300 * (($yv - $sc['ymin']) / ($sc['ymax'] - $sc['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $gy) ?>" x2="800" y2="<?= sprintf('%.2F', $gy) ?>" stroke="#E3E5E0" stroke-width="1" vector-effect="non-scaling-stroke" />
            <?php endfor; ?>
            <?php foreach ($sc['refs'] as $ref):
              $ry = 300 - 300 * (($ref['v'] - $sc['ymin']) / ($sc['ymax'] - $sc['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $ry) ?>" x2="800" y2="<?= sprintf('%.2F', $ry) ?>" stroke="<?= $ref['c'] ?>" stroke-width="1.5" stroke-dasharray="6 3" vector-effect="non-scaling-stroke" />
            <?php endforeach; ?>
            <?php if (!empty($sc['expanded']) && $sc['limit'] > $sc['ymin'] && $sc['limit'] < $sc['ymax']):
              $ly = 300 - 300 * (($sc['limit'] - $sc['ymin']) / ($sc['ymax'] - $sc['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $ly) ?>" x2="800" y2="<?= sprintf('%.2F', $ly) ?>" stroke="#B97A0C" stroke-width="1.5" stroke-dasharray="2 4" vector-effect="non-scaling-stroke" />
            <?php endif; ?>
            </g>
            <?php foreach (array('R', 'S', 'T') as $sk): $stl = $trend_style[$sk]; ?>
            <polyline data-series="<?= $sk ?>" fill="none" stroke="<?= $stl['var'] ?>" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"<?= ($stl['dash'] !== '') ? ' stroke-dasharray="' . $stl['dash'] . '"' : '' ?> vector-effect="non-scaling-stroke" points="<?= $day_line($t_c['series'][$sk]['pts'], $sc['ymin'], $sc['ymax']) ?>" />
            <?php endforeach; ?>
          </svg>
          <div data-refchips="<?= html_escape($tkey) ?>" class="pointer-events-none absolute inset-0">
            <?php foreach ($sc['refs'] as $ref):
              $frac = ($sc['ymax'] - $ref['v']) / ($sc['ymax'] - $sc['ymin']); ?>
            <span class="absolute right-2 -translate-y-1/2 rounded border border-ems-line bg-white/90 px-1.5 py-px text-[10px] font-medium text-ems-muted" style="top:<?= sprintf('%.2F%%', $frac * 100) ?>"><?= html_escape($ref['label']) ?></span>
            <?php endforeach; ?>
          </div>
          <!-- crosshair + dot + tooltip (JS lvmdp-trend.js; posisi % diperbarui) -->
          <div data-hx class="pointer-events-none absolute inset-y-0 hidden w-px bg-ems-steel-400/60"></div>
          <?php foreach (array('R' => 'var(--ems-fasa-r)', 'S' => 'var(--ems-fasa-s)', 'T' => 'var(--ems-ink)') as $sk => $cv): ?>
          <div data-dot="<?= $sk ?>" class="pointer-events-none absolute hidden size-2 -translate-x-1/2 -translate-y-1/2 rounded-full" style="background:<?= $cv ?>"></div>
          <?php endforeach; ?>
          <div data-tip class="pointer-events-none absolute hidden z-10 min-w-max rounded-md border border-ems-line bg-white px-2.5 py-2 text-[11px] leading-4 tabular-nums text-ems-ink"></div>
        </div>
      </div>

      <div class="mt-1 flex">
        <div class="w-12 shrink-0"></div>
        <div class="flex min-w-0 flex-1 justify-between px-px text-[10px] tabular-nums text-ems-muted">
          <span>00:00</span><span>03:00</span><span>06:00</span><span>09:00</span><span>12:00</span><span>15:00</span><span>18:00</span><span>21:00</span><span>24:00</span>
        </div>
      </div>
    </div>
    <?php endforeach; ?>

    <p class="border-t border-ems-line px-4 py-3 text-[11px] text-ems-muted md:px-6">
      Pita kuning = WBP 18:00–22:00 · Skala vertikal menyesuaikan data (batas dasar: L-N 240 V · L-L 420 V · Arus 1200 A) · ↑/↓ = di atas/di bawah batas dasar · — = tidak ada data · Titik = waktu baris tercatat · Statistik dari titik tercatat (Rata = mean, bukan weighted) · Data menit dari DB ems.data_electricity_iot5.
    </p>
  </div>

  <!-- P-H fase 2: kartu "Power Hari Ini" (DB ems.data_electricity_iot5, dinamis, hover interaktif — SS5-T4) -->
  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:items-center md:px-6">
      <div class="flex min-w-0 items-center gap-3">
        <span class="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="power" class="size-4 text-ems-muted"></i>
        </span>
        <div class="flex min-w-0 flex-col">
          <h2 class="truncate text-sm font-semibold">Power Hari Ini</h2>
          <p class="truncate text-xs text-ems-muted">Daya aktif per catatan — pola beban hari ini</p>
        </div>
      </div>
      <div class="flex shrink-0 items-center gap-2 sm:ml-auto">
        <span data-date="ph" class="truncate text-xs tabular-nums text-ems-muted"><?= html_escape($power['tgl']) ?></span>
        <span class="shrink-0 rounded-full bg-ems-ok/10 px-2.5 py-1 text-[11px] font-semibold text-ems-ok">Sumber DB</span>
      </div>
    </div>

    <div class="px-4 py-4 md:px-6">
      <?php $psc = $power['scale']; ?>
      <div class="flex">
        <div data-yaxis="ph" class="relative w-12 shrink-0 h-40 sm:h-52 lg:h-60">
          <?php for ($yv = $psc['ymin']; $yv <= $psc['ymax'] + 0.001; $yv += $psc['ystep']):
            $frac = ($psc['ymax'] - $yv) / ($psc['ymax'] - $psc['ymin']); ?>
          <span class="absolute right-2 -translate-y-1/2 text-[10px] tabular-nums text-ems-muted" style="top:<?= sprintf('%.2F%%', $frac * 100) ?>"><?= (int) $yv ?></span>
          <?php endfor; ?>
        </div>
        <div class="relative min-w-0 flex-1 cursor-crosshair rounded-md h-40 sm:h-52 lg:h-60 outline-offset-2 focus-visible:outline-2 focus-visible:outline-ems-amber" tabindex="0" role="img" data-trend="ph" aria-label="Daya aktif (kW) hari ini WIB, dari pencatatan DB 00:00–24:00">
          <svg class="block size-full" viewBox="0 0 800 300" preserveAspectRatio="none" aria-hidden="true">
            <rect x="600" y="0" width="133.33" height="300" fill="rgba(234,165,0,0.10)" />
            <g data-deco>
            <?php for ($yv = $psc['ymin']; $yv <= $psc['ymax'] + 0.001; $yv += $psc['ystep']):
              $gy = 300 - 300 * (($yv - $psc['ymin']) / ($psc['ymax'] - $psc['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $gy) ?>" x2="800" y2="<?= sprintf('%.2F', $gy) ?>" stroke="#E3E5E0" stroke-width="1" vector-effect="non-scaling-stroke" />
            <?php endfor; ?>
            </g>
            <polyline data-series="K" fill="none" stroke="#191C1F" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round" vector-effect="non-scaling-stroke" points="<?= $day_line($power['pts'], $psc['ymin'], $psc['ymax']) ?>" />
            <circle data-peakdot <?php if ($power['peak']['val'] !== NULL): ?>cx="<?= $power['peak']['cpx'] ?>" cy="<?= $power['peak']['cpy'] ?>" r="3" fill="#191C1F"<?php endif; ?> />
          </svg>
          <span data-cell="ph:peak" class="absolute -translate-x-1/2 -translate-y-full whitespace-nowrap rounded border border-ems-line bg-white/90 px-1.5 py-px text-[10px] font-medium tabular-nums text-ems-muted" style="top:<?= $power['peak']['cy'] ?>%;left:<?= $power['peak']['x'] ?>%">Maks <?= $dash_num($power['peak']['val'], 1) ?> kW <?= html_escape($power['peak']['t']) ?></span>
          <div data-hx class="pointer-events-none absolute inset-y-0 hidden w-px bg-ems-steel-400/60"></div>
          <div data-dot="K" class="pointer-events-none absolute hidden size-2 -translate-x-1/2 -translate-y-1/2 rounded-full" style="background:var(--ems-ink)"></div>
          <div data-tip class="pointer-events-none absolute hidden z-10 min-w-max rounded-md border border-ems-line bg-white px-2.5 py-2 text-[11px] leading-4 tabular-nums text-ems-ink"></div>
        </div>
      </div>

      <div class="mt-1 flex">
        <div class="w-12 shrink-0"></div>
        <div class="flex min-w-0 flex-1 justify-between px-px text-[10px] tabular-nums text-ems-muted">
          <span>00:00</span><span>03:00</span><span>06:00</span><span>09:00</span><span>12:00</span><span>15:00</span><span>18:00</span><span>21:00</span><span>24:00</span>
        </div>
      </div>

      <div class="mt-3 grid grid-cols-3 gap-2 sm:gap-4">
        <?php $p_st = $power['stat']; foreach (array('max' => 'Maks', 'avg' => 'Rata', 'min' => 'Min') as $fld => $plbl): ?>
        <div class="min-w-0">
          <span class="block text-[10px] text-ems-muted"><?= $plbl ?></span>
          <span class="block truncate tabular-nums text-ems-ink">
            <span data-cell="ph:<?= $fld ?>"><?= $dash_num($p_st[$fld], 1) ?></span>
            <span class="text-xs font-normal text-ems-muted">kW</span>
            <?php if ($fld !== 'avg'): ?>
            <span class="text-[10px] text-ems-muted"><?= html_escape($p_st['r' . $fld]) ?></span>
            <?php endif; ?>
          </span>
        </div>
        <?php endforeach; ?>
      </div>
    </div>

    <p class="border-t border-ems-line px-4 py-3 text-[11px] text-ems-muted md:px-6">
      Pita kuning = WBP 18:00–22:00 · Skala tetap 0–600 kW · Titik ink = puncak daya · Titik = waktu baris tercatat · Data menit dari DB ems.data_electricity_iot5.
    </p>
  </div>

  <p class="truncate text-xs text-ems-muted">Jarum gauge mengikuti data MQTT live dengan animasi halus (display saja, tanpa database).</p>
</div>
<script type="application/json" id="trends-data"><?= $trends_json ?></script>
<script src="<?php echo base_url('assets/js/mqtt.min.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/lvmdp-live.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/lvmdp-trend.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/lvmdp-tarif7.js'); ?>"></script>
