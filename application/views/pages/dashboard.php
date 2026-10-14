<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Halaman: Dashboard — blank yang dirancang + motion animate + palette industri.
$badge = '<span class="inline-flex items-center gap-2 rounded-full border border-ems-line bg-white px-3 py-1.5 text-xs font-medium text-ems-muted"><span class="live-dot size-2 rounded-full bg-ems-ok"></span>Periode: —</span>';
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle, 'action' => $badge));
?>

<!-- KPI placeholder: stagger fade-up -->
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <?php
  $kpis = array(
    array('icon' => 'zap',        'label' => 'Total Konsumsi', 'sub' => 'kWh bulan berjalan — segera hadir'),
    array('icon' => 'gauge',      'label' => 'Beban Puncak',   'sub' => 'kW tertinggi — segera hadir'),
    array('icon' => 'activity',   'label' => 'Power Factor',   'sub' => 'rata-rata — segera hadir'),
    array('icon' => 'plug-zap',   'label' => 'Panel Online',   'sub' => '3 unit terpantau — segera hadir'),
  );
  foreach ($kpis as $i => $kpi):
  ?>
  <div class="reveal flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4" style="--reveal-delay: <?= $i * 80 ?>ms">
    <div class="flex items-center gap-2">
      <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
        <i data-lucide="<?= $kpi['icon'] ?>" class="size-4 text-ems-muted"></i>
      </span>
      <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted"><?= $kpi['label'] ?></span>
    </div>
    <div class="skeleton h-7 w-2/3 rounded-md"></div>
    <p class="truncate text-xs text-ems-muted"><?= $kpi['sub'] ?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Grafik placeholder: garis menggambar saat masuk viewport -->
<div class="reveal flex flex-col gap-4 rounded-lg border border-ems-line bg-white p-4 md:p-6" style="--reveal-delay: 120ms">
  <div class="flex items-center gap-3">
    <div class="flex min-w-0 flex-col">
      <h2 class="truncate text-sm font-semibold">Tren Konsumsi</h2>
      <p class="truncate text-xs text-ems-muted">Kurva harian akan tampil di sini</p>
    </div>
    <span class="ml-auto shrink-0 rounded-full bg-ems-ambersoft px-2.5 py-1 text-[11px] font-semibold text-ems-amberdeep">Segera hadir</span>
  </div>
  <div class="overflow-x-auto">
    <svg viewBox="0 0 560 180" class="h-44 w-full min-w-[480px]" role="img" aria-label="Placeholder grafik tren konsumsi">
      <g stroke="#E9EBE7" stroke-width="1">
        <line x1="0" y1="36" x2="560" y2="36" />
        <line x1="0" y1="76" x2="560" y2="76" />
        <line x1="0" y1="116" x2="560" y2="116" />
        <line x1="0" y1="156" x2="560" y2="156" />
      </g>
      <path class="chart-line" d="M0,140 C60,132 90,96 140,92 C190,88 210,110 260,84 C310,58 340,40 400,48 C460,56 500,72 560,44"
        fill="none" stroke="#EAA500" stroke-width="2.5" stroke-linecap="round" />
      <path class="chart-line delay-2" d="M0,156 C70,150 120,128 180,126 C250,124 300,138 360,120 C420,102 480,110 560,92"
        fill="none" stroke="#9AA1AD" stroke-width="2" stroke-dasharray="6 6" stroke-linecap="round" />
      <circle cx="400" cy="48" r="4" fill="#EAA500" stroke="#fff" stroke-width="2" />
    </svg>
  </div>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
  <!-- Empty state utama -->
  <div class="reveal rounded-lg border border-ems-line bg-white lg:col-span-3" style="--reveal-delay: 200ms">
    <div class="border-b border-ems-line px-4 py-3 md:px-6">
      <h2 class="truncate text-sm font-semibold">Ringkasan Sistem</h2>
    </div>
    <?php $this->load->view('partials/empty_state', array(
      'icon'  => 'layout-dashboard',
      'title' => 'Belum ada data ringkasan',
      'desc'  => 'KPI total kWh, beban puncak, dan status panel akan tampil di sini.',
      'note'  => 'Modul akan diisi pada tahap berikutnya tanpa mengubah layout.',
      'animate' => true,
    )); ?>
  </div>

  <!-- Color palette industri: Graphite + Concrete + Signal Amber -->
  <div class="reveal flex flex-col gap-4 rounded-lg border border-ems-line bg-white p-4 md:p-6 lg:col-span-2" style="--reveal-delay: 280ms">
    <div class="flex min-w-0 flex-col">
      <h2 class="truncate text-sm font-semibold">Industrial Palette</h2>
      <p class="text-xs text-ems-muted">90% netral + 10% amber — tenang seperti panel gardu.</p>
    </div>
    <div class="flex flex-col gap-2.5">
      <div class="flex items-center gap-3">
        <span class="size-8 shrink-0 rounded-lg border border-ems-line" style="background:#14171B"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Graphite 900 — Sidebar</span>
          <span class="truncate text-xs text-ems-muted">#14171B</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="size-8 shrink-0 rounded-lg border border-ems-line" style="background:#1E2329"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Graphite 800 — Active item</span>
          <span class="truncate text-xs text-ems-muted">#1E2329</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="size-8 shrink-0 rounded-lg border border-ems-line" style="background:#F2F3F0"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Concrete — Background</span>
          <span class="truncate text-xs text-ems-muted">#F2F3F0</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="size-8 shrink-0 rounded-lg" style="background:#EAA500"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Signal Amber — Aksen energi</span>
          <span class="truncate text-xs text-ems-muted">#EAA500 · dipakai hemat</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="size-8 shrink-0 rounded-lg" style="background:#1A7F4D"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Moss — Status normal</span>
          <span class="truncate text-xs text-ems-muted">#1A7F4D</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="size-8 shrink-0 rounded-lg border border-ems-line" style="background:#FFFFFF"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Card White — Permukaan</span>
          <span class="truncate text-xs text-ems-muted">#FFFFFF · border #E3E5E0</span>
        </div>
      </div>
    </div>
    <div class="h-px bg-ems-line" role="separator"></div>
    <p class="text-xs leading-relaxed text-ems-muted">Aturan pakai: teks utama <span class="font-semibold text-ems-ink">#191C1F</span>, muted <span class="font-semibold text-ems-ink">#6B7280</span>, jangan tambah warna lain sebelum modul grafik.</p>
  </div>
</div>
