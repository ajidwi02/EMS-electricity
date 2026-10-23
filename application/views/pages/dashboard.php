<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Halaman: Dashboard — LIVE dari DB `ems` (CP-016): KPI + grafik kW/jam + ringkasan panel.
// CP-037: badge "Periode: Hari ini · ..." dihapus — page_header tidak dipanggil lagi
// (partial tetap dipakai export_data untuk slot tombol Export).

// --- helper format: NULL -> em-dash (aturan "NULL = no data", bukan 0) -----
$fmt = function ($v, $dec = 1) {
  return ($v === NULL) ? '—' : number_format((float) $v, $dec, '.', '');
};

$on_txt = (int) $kpi['online'] . '/' . (int) $kpi['panels_n'];
$kpis = array(
  array('icon' => 'zap',      'label' => 'Total Konsumsi', 'val' => $fmt($kpi['kwh_today']),  'unit' => ($kpi['kwh_today'] === NULL ? '' : 'kWh'), 'sub' => 'kWh hari ini — jumlah per panel'),
  array('icon' => 'gauge',    'label' => 'Beban Puncak',   'val' => $fmt($kpi['peak_kw']),    'unit' => ($kpi['peak_kw']   === NULL ? '' : 'kW'),  'sub' => 'kW terbesar hari ini'),
  array('icon' => 'activity', 'label' => 'Power Factor',   'val' => $fmt($kpi['avg_pf'], 2),  'unit' => ($kpi['avg_pf']    === NULL ? '' : 'pf'),  'sub' => 'rata-rata hari ini'),
  array('icon' => 'plug-zap', 'label' => 'Panel Online',   'val' => $on_txt,                  'unit' => 'unit',                                    'sub' => 'pembaruan ≤5 menit terakhir'),
);

// --- grafik: polyline bucket kW/jam dari Readings_model::hourly_avg_today --
$pts = array();
$last_xy = NULL;
if (!empty($chart)) {
  $vals = array();
  foreach ($chart as $b) {
    if ($b['avg_kw'] !== NULL) { $vals[] = (float) $b['avg_kw']; }
  }
  $nb = count($vals);
  if ($nb > 0) {
    $X0 = 10; $X1 = 550; $Y1 = 150; $Y0 = 22;   // viewBox 560x180; grid y 36/76/116
    $span = max(max($vals), 0.001);
    foreach ($vals as $i => $v) {
      $x = ($nb === 1) ? 280 : $X0 + (($X1 - $X0) * $i / ($nb - 1));
      $y = $Y1 - (($v / $span) * ($Y1 - $Y0));
      $pts[] = array(round($x, 1), round($y, 1));
    }
    $last_xy = $pts[$nb - 1];
  }
}
$pts_str = '';
foreach ($pts as $pt) { $pts_str .= $pt[0] . ',' . $pt[1] . ' '; }
$pts_str = trim($pts_str);
?>
<!-- KPI hari ini: stagger fade-up (GR-08 — motion dashboard saja) -->
<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
  <?php foreach ($kpis as $i => $kpi_item): ?>
  <div class="reveal flex flex-col gap-3 rounded-lg border border-ems-line bg-white p-4" style="--reveal-delay: <?= (int) ($i * 80) ?>ms">
    <div class="flex items-center gap-2">
      <span class="flex size-8 items-center justify-center rounded-lg bg-[#EFF0ED]">
        <i data-lucide="<?= $kpi_item['icon'] ?>" class="size-4 text-ems-muted"></i>
      </span>
      <span class="truncate text-xs font-medium uppercase tracking-wider text-ems-muted"><?= $kpi_item['label'] ?></span>
    </div>
    <div class="flex items-baseline gap-1.5">
      <span class="truncate text-2xl font-semibold tabular-nums text-ems-ink"><?= $kpi_item['val'] ?></span>
      <?php if ($kpi_item['unit'] !== ''): ?>
        <span class="text-xs font-medium text-ems-muted"><?= $kpi_item['unit'] ?></span>
      <?php endif; ?>
    </div>
    <p class="truncate text-xs text-ems-muted"><?= $kpi_item['sub'] ?></p>
  </div>
  <?php endforeach; ?>
</div>

<!-- Grafik live: garis menggambar saat masuk viewport (q bucket DB nyata) -->
<div class="reveal flex flex-col gap-4 rounded-lg border border-ems-line bg-white p-4 md:p-6" style="--reveal-delay: 120ms">
  <div class="flex items-center gap-3">
    <div class="flex min-w-0 flex-col">
      <h2 class="truncate text-sm font-semibold">Tren Konsumsi</h2>
      <p class="truncate text-xs text-ems-muted">kW rata-rata per jam · hari ini · WIB</p>
    </div>
    <span class="ml-auto shrink-0 rounded-full bg-ems-ambersoft px-2.5 py-1 text-[11px] font-semibold text-ems-amberdeep"><?= ($chart_name !== '' ? html_escape($chart_name) . ' · live' : 'Menunggu data') ?></span>
  </div>
  <div class="overflow-x-auto">
    <svg viewBox="0 0 560 180" class="h-44 w-full min-w-[480px]" role="img" aria-label="Grafik tren kW rata-rata per jam hari ini">
      <g stroke="#E9EBE7" stroke-width="1">
        <line x1="0" y1="36" x2="560" y2="36" />
        <line x1="0" y1="76" x2="560" y2="76" />
        <line x1="0" y1="116" x2="560" y2="116" />
        <line x1="0" y1="156" x2="560" y2="156" />
      </g>
      <?php if (count($pts) >= 2): ?>
        <polyline class="chart-line" points="<?= html_escape($pts_str) ?>" fill="none" stroke="#EAA500" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" />
        <circle cx="<?= $last_xy[0] ?>" cy="<?= $last_xy[1] ?>" r="4" fill="#EAA500" stroke="#fff" stroke-width="2" />
      <?php elseif (count($pts) === 1): ?>
        <circle cx="<?= $pts[0][0] ?>" cy="<?= $pts[0][1] ?>" r="4" fill="#EAA500" stroke="#fff" stroke-width="2" />
      <?php else: ?>
        <text x="280" y="94" text-anchor="middle" fill="#6B7280" font-size="12">Menunggu menit pembacaan pertama — grafik menarik sendiri saat data masuk</text>
      <?php endif; ?>
    </svg>
  </div>
</div>

<div class="grid grid-cols-1 gap-4 lg:grid-cols-5">
  <!-- Ringkasan sistem: per-panel, nilai terbaru dari DB -->
  <div class="reveal rounded-lg border border-ems-line bg-white lg:col-span-3" style="--reveal-delay: 200ms">
    <div class="border-b border-ems-line px-4 py-3 md:px-6">
      <h2 class="truncate text-sm font-semibold">Ringkasan Sistem</h2>
      <p class="truncate text-xs text-ems-muted">Panel aktif · nilai terbaru &amp; konsumsi hari ini (WIB)</p>
    </div>
    <div class="overflow-x-auto">
      <table class="w-full min-w-[560px] text-sm" role="table">
        <thead class="bg-[#EFF0ED]">
          <tr class="border-b border-ems-line">
            <th class="px-3 py-2.5 text-left font-semibold"><span class="block">Panel</span><span class="block text-[11px] font-normal text-ems-muted">status pembaruan</span></th>
            <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Beban</span><span class="block text-[11px] font-normal text-ems-muted">kiloWatt (kW) terbaru</span></th>
            <th class="px-3 py-2.5 text-right font-semibold"><span class="block">PF</span><span class="block text-[11px] font-normal text-ems-muted">power factor</span></th>
            <th class="px-3 py-2.5 text-right font-semibold"><span class="block">kWh Hari Ini</span><span class="block text-[11px] font-normal text-ems-muted">selisih max−min</span></th>
            <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Pembaruan</span><span class="block text-[11px] font-normal text-ems-muted">relatif ke WIB</span></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($panel_rows as $r): ?>
          <tr class="border-b border-ems-line/70 last:border-0 hover:bg-[#F7F8F6]">
            <td class="whitespace-nowrap px-3 py-2">
              <span class="inline-flex items-center gap-2">
                <span class="inline-flex size-2 shrink-0 rounded-full <?= ($r['mins_old'] !== NULL && $r['mins_old'] <= 5) ? 'bg-ems-ok' : 'bg-ems-line' ?>"></span>
                <span class="truncate"><?= html_escape($r['name']) ?></span>
              </span>
            </td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums"><?= $fmt($r['kw']) ?></td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums"><?= $fmt($r['pf'], 2) ?></td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums"><?= $fmt($r['kwh_today']) ?></td>
            <td class="whitespace-nowrap px-3 py-2 text-right">
              <?php
              if ($r['mins_old'] === NULL) {
                echo '<span class="text-ems-muted">—</span>';
              } elseif ($r['mins_old'] <= 0) {
                echo 'baru saja';
              } elseif ($r['mins_old'] < 60) {
                echo html_escape((string) (int) $r['mins_old'] . ' mnt lalu');
              } elseif ($r['mins_old'] < 1440) {
                echo html_escape((string) (int) floor($r['mins_old'] / 60) . ' jam lalu');
              } else {
                echo html_escape((string) (int) floor($r['mins_old'] / 1440) . ' hari lalu');
              }
              ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
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
        <span class="flex size-8 shrink-0 rounded-lg" style="background:#EAA500"></span>
        <div class="flex min-w-0 flex-col">
          <span class="truncate text-xs font-semibold">Signal Amber — Aksen energi</span>
          <span class="truncate text-xs text-ems-muted">#EAA500 · dipakai hemat</span>
        </div>
      </div>
      <div class="flex items-center gap-3">
        <span class="flex size-8 shrink-0 rounded-lg" style="background:#1A7F4D"></span>
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
