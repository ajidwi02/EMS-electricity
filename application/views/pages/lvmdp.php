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

  <p class="truncate text-xs text-ems-muted">Jarum gauge mengikuti data MQTT live dengan animasi halus (display saja, tanpa database).</p>
</div>
<script src="<?php echo base_url('assets/js/mqtt.min.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/lvmdp-live.js'); ?>"></script>
