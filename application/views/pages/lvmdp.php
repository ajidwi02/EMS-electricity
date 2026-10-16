<?php defined('BASEPATH') OR exit('No direct script access allowed');
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle));
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

  <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="zap" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Tegangan L-N</span>
      </div>
      <div class="flex flex-col gap-2">
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">V-R</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="v_r" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">V-S</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="v_s" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">V-T</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="v_t" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="activity" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Tegangan L-L</span>
      </div>
      <div class="flex flex-col gap-2">
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">V-RS</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="v_rs" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">V-ST</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="v_st" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">V-RT</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="v_rt" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">V</span></span>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="plug-zap" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Arus</span>
      </div>
      <div class="flex flex-col gap-2">
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">I-R</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="i_r" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">A</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">I-S</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="i_s" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">A</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">I-T</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="i_t" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">A</span></span>
        </div>
      </div>
    </div>

    <div class="flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4">
      <div class="flex items-center gap-2">
        <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
          <i data-lucide="gauge" class="size-4 text-ems-muted"></i>
        </span>
        <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted">Daya &amp; Energi</span>
      </div>
      <div class="flex flex-col gap-2">
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">PF</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="pf" data-dec="2">-</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">kW</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="kw" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">kW</span></span>
        </div>
        <div class="flex items-baseline gap-2">
          <span class="text-xs text-ems-muted">kWh</span>
          <span class="ml-auto truncate text-xl font-semibold tabular-nums"><span data-f="Kwh" data-dec="1">-</span> <span class="text-xs font-normal text-ems-muted">kWh</span></span>
        </div>
      </div>
    </div>
  </div>

  <p class="truncate text-xs text-ems-muted">Data live dari broker (display saja, tanpa database). Nilai tampil apa adanya mengikuti pesan MQTT.</p>
</div>
<script src="<?php echo base_url('assets/js/mqtt.min.js'); ?>"></script>
<script src="<?php echo base_url('assets/js/lvmdp-live.js'); ?>"></script>
