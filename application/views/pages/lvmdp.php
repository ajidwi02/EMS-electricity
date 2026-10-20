<?php defined('BASEPATH') OR exit('No direct script access allowed');
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle));
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

// ── L-T fase 1: defs Tren 24 Jam — skala & referensi LOCKED §2.2–2.4 plans/ems-lvmdp-trend-charts.md ──
$trend_defs = array(
  'ln' => array(
    'ymin' => 190, 'ymax' => 240, 'ystep' => 10,
    'refs' => array(
      array('v' => 231, 'label' => 'Batas +5% 231',  'c' => '#9AA1AD'),
      array('v' => 220, 'label' => 'Nominal 220',    'c' => '#6B7280'),
      array('v' => 198, 'label' => 'Batas −10% 198', 'c' => '#9AA1AD'),
    ),
  ),
  'll' => array(
    'ymin' => 330, 'ymax' => 420, 'ystep' => 10,
    'refs' => array(
      array('v' => 399, 'label' => 'Batas +5% 399',  'c' => '#9AA1AD'),
      array('v' => 380, 'label' => 'Nominal 380',    'c' => '#6B7280'),
      array('v' => 342, 'label' => 'Batas −10% 342', 'c' => '#9AA1AD'),
    ),
  ),
  'a' => array(
    'ymin' => 0, 'ymax' => 1200, 'ystep' => 200,
    'refs' => array(
      array('v' => 1000, 'label' => 'Nominal FLA 1000', 'c' => '#C2410C'),
    ),
  ),
);
$trend_style = array(
  'R' => array('var' => 'var(--ems-fasa-r)', 'dash' => ''),
  'S' => array('var' => 'var(--ems-fasa-s)', 'dash' => '10 4'),
  'T' => array('var' => 'var(--ems-ink)',    'dash' => '2 4'),
);
// polyline points 289 titik: x = i/288*800 · y = 300 − 300*(v−ymin)/(ymax−ymin)
$trend_points = function ($vals, $ymin, $ymax) {
  $last = count($vals) - 1;
  $p    = '';
  foreach ($vals as $i => $v) {
    $p .= sprintf('%.2F,%.2F ', ($i / $last) * 800,
      300 - 300 * (($v - $ymin) / ($ymax - $ymin)));
  }
  return rtrim($p);
};
$trend_num = function ($v, $dec = 1) {
  return number_format((float) $v, $dec, '.', '');
};
?>
<div id="lvmdp-root"
  data-ws="<?php echo html_escape(isset($mqtt_ws_url) ? $mqtt_ws_url : 'ws://localhost:8083/mqtt'); ?>"
  data-topic="<?php echo html_escape(isset($mqtt_topic) ? $mqtt_topic : 'data/ems/lvmdp/+'); ?>"
  data-user="<?php echo html_escape(isset($mqtt_user) ? $mqtt_user : ''); ?>"
  data-pass="<?php echo html_escape(isset($mqtt_pass) ? $mqtt_pass : ''); ?>"
  class="flex flex-col gap-6">

  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex items-center gap-3 border-b border-ems-line px-4 py-3 md:px-6">
      <span id="lvmdp-dot" class="size-2 shrink-0 rounded-full bg-ems-muted"></span>
      <h2 class="truncate text-sm font-semibold">Status Koneksi</h2>
      <span id="lvmdp-conn" class="ml-auto shrink-0 truncate text-xs text-ems-muted">Menghubungkan…</span>
    </div>
    <div class="flex flex-col gap-3 px-4 py-4 md:flex-row md:items-center md:gap-6 md:px-6">
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
  </div>

  <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">
    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="zap" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Tegangan L-N</span>
      </div>
      <div class="flex flex-col gap-5">
        <?php foreach ($ln_gauges as $g): ?>
        <div class="flex items-center gap-4">
          <svg class="gauge size-28 shrink-0" viewBox="0 0 120 70" role="img" aria-label="<?php echo html_escape($g['aria']); ?>" data-gauge="ln" data-f="<?php echo html_escape($g['f']); ?>"></svg>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted"><?php echo html_escape($g['label']); ?></span>
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

    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="activity" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Tegangan L-L</span>
      </div>
      <div class="flex flex-col gap-5">
        <?php foreach ($ll_gauges as $g): ?>
        <div class="flex items-center gap-4">
          <svg class="gauge size-28 shrink-0" viewBox="0 0 120 70" role="img" aria-label="<?php echo html_escape($g['aria']); ?>" data-gauge="ll" data-f="<?php echo html_escape($g['f']); ?>"></svg>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted"><?php echo html_escape($g['label']); ?></span>
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

    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="plug-zap" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Arus</span>
      </div>
      <div class="flex flex-col gap-5">
        <?php foreach ($a_gauges as $g): ?>
        <div class="flex items-center gap-4">
          <svg class="gauge size-28 shrink-0" viewBox="0 0 120 70" role="img" aria-label="<?php echo html_escape($g['aria']); ?>" data-gauge="a" data-f="<?php echo html_escape($g['f']); ?>"></svg>
          <div class="flex min-w-0 flex-1 flex-col gap-1">
            <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted"><?php echo html_escape($g['label']); ?></span>
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
    <div class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
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
    </div>
  </div>

  <!-- L-T fase 1: kartu Tren 24 Jam (dummy, statis) -->
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
        <span class="truncate text-xs tabular-nums text-ems-muted"><?= html_escape($trends['tgl']) ?></span>
        <span class="shrink-0 rounded-full bg-ems-ambersoft px-2.5 py-1 text-[11px] font-semibold text-ems-amberdeep">Data dummy</span>
      </div>
    </div>

    <?php $ci = 0; foreach ($trend_defs as $tkey => $def):
      $t_c    = $trends['charts'][$tkey];
      $plot_h = 'h-40 sm:h-52 lg:h-60';
      $ci++;
      $is_last  = ($ci === 3);
      $is_first = ($ci === 1);
      $pad      = $is_first ? 'pt-3 pb-4' : ($is_last ? 'pt-4 pb-3' : 'pt-4 pb-4');
    ?>
    <div class="<?php echo $is_last ? '' : 'border-b border-ems-line/70 '; ?><?php echo $pad; ?> px-4 md:px-6">
      <h3 class="flex min-w-0 items-baseline gap-2">
        <span class="truncate text-sm font-semibold"><?= html_escape($t_c['title']) ?></span>
        <span class="shrink-0 text-xs font-normal text-ems-muted"><?= html_escape($t_c['unit']) ?></span>
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
        <?php foreach (array('R', 'S', 'T') as $sk): $st = $t_c['series'][$sk]; ?>
        <div class="flex min-w-0 flex-wrap items-baseline gap-x-1 tabular-nums">
          <span class="font-medium"><?= $trend_num($st[$fld], ($tkey === 'a') ? 0 : 1) ?> <?= ($tkey === 'a') ? 'A' : 'V' ?></span>
          <?php if ($fld !== 'avg'): ?>
          <span class="text-[10px] text-ems-muted"><?= html_escape($st['r' . $fld]) ?></span>
          <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endforeach; ?>
      </div>

      <div class="mt-3 flex">
        <div class="relative w-12 shrink-0 <?= $plot_h ?>">
          <?php for ($yv = $def['ymin']; $yv <= $def['ymax'] + 0.001; $yv += $def['ystep']):
            $frac = ($def['ymax'] - $yv) / ($def['ymax'] - $def['ymin']); ?>
          <span class="absolute right-2 -translate-y-1/2 text-[10px] tabular-nums text-ems-muted" style="top:<?= sprintf('%.2F%%', $frac * 100) ?>"><?= (int) $yv ?></span>
          <?php endfor; ?>
        </div>
        <div class="relative min-w-0 flex-1 <?= $plot_h ?>">
          <svg class="block size-full" viewBox="0 0 800 300" preserveAspectRatio="none" role="img" aria-label="<?= html_escape($t_c['title']) ?> hari ini, WIB, data dummy">
            <rect x="600" y="0" width="133.33" height="300" fill="rgba(234,165,0,0.10)" />
            <?php for ($yv = $def['ymin']; $yv <= $def['ymax'] + 0.001; $yv += $def['ystep']):
              $gy = 300 - 300 * (($yv - $def['ymin']) / ($def['ymax'] - $def['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $gy) ?>" x2="800" y2="<?= sprintf('%.2F', $gy) ?>" stroke="#E3E5E0" stroke-width="1" vector-effect="non-scaling-stroke" />
            <?php endfor; ?>
            <?php foreach ($def['refs'] as $ref):
              $ry = 300 - 300 * (($ref['v'] - $def['ymin']) / ($def['ymax'] - $def['ymin'])); ?>
            <line x1="0" y1="<?= sprintf('%.2F', $ry) ?>" x2="800" y2="<?= sprintf('%.2F', $ry) ?>" stroke="<?= $ref['c'] ?>" stroke-width="1.5" stroke-dasharray="6 3" vector-effect="non-scaling-stroke" />
            <?php endforeach; ?>
            <?php foreach (array('R', 'S', 'T') as $sk): $stl = $trend_style[$sk]; ?>
            <polyline fill="none" stroke="<?= $stl['var'] ?>" stroke-width="2.5" stroke-linejoin="round" stroke-linecap="round"<?= ($stl['dash'] !== '') ? ' stroke-dasharray="' . $stl['dash'] . '"' : '' ?> vector-effect="non-scaling-stroke" points="<?= $trend_points($t_c['series'][$sk]['vals'], $def['ymin'], $def['ymax']) ?>" />
            <?php endforeach; ?>
          </svg>
          <?php foreach ($def['refs'] as $ref):
            $frac = ($def['ymax'] - $ref['v']) / ($def['ymax'] - $def['ymin']); ?>
          <span class="absolute right-2 -translate-y-1/2 rounded border border-ems-line bg-white/90 px-1.5 py-px text-[10px] font-medium text-ems-muted" style="top:<?= sprintf('%.2F%%', $frac * 100) ?>"><?= html_escape($ref['label']) ?></span>
          <?php endforeach; ?>
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
      Pita kuning = WBP 18:00–22:00 · Skala tetap (L-N 190–240 V · L-L 330–420 V · Arus 0–1200 A) · Data dummy — data realtime tetap dari MQTT.
    </p>
  </div>

  <p class="truncate text-xs text-ems-muted">Jarum gauge mengikuti data MQTT live dengan animasi halus (display saja, tanpa database).</p>
</div>
<script src="<?php echo base_url('assets/js/mqtt.min.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/lvmdp-live.js'); ?>"></script>
