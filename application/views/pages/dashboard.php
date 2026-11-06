<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Halaman: Dashboard — LIVE dari DB `ems` (CP-016): KPI + donut Distribusi Tarif + Ringkasan Sistem.
// CP-070: kartu chart "Tren Konsumsi" (kW/jam) DIHAPUS user — tampilan lebih minimalis.
// CP-037: badge "Periode: Hari ini · ..." dihapus — page_header tidak dipanggil lagi
// (partial tetap dipakai export_data untuk slot tombol Export).

// --- helper format: NULL -> em-dash (aturan "NULL = no data", bukan 0) -----
$fmt = function ($v, $dec = 1) {
  return ($v === NULL) ? '—' : number_format((float) $v, $dec, '.', '');
};

// --- D-T: format kartu "Distribusi Tarif Hari Ini" --------------------------
$dp_rp = function ($v) {
  return ($v === NULL) ? '—' : 'Rp ' . number_format((float) $v, 0, ',', '.');
};
$dp_kwh = function ($v) {
  return ($v === NULL) ? '—' : number_format((float) $v, 2, ',', '.');
};
$dp_pct = function ($v) {
  return ($v === NULL) ? '—' : number_format((float) $v, 2, ',', '.') . '%';
};

$on_txt = (int) $kpi['online'] . '/' . (int) $kpi['panels_n'];
$kpis = array(
  array('icon' => 'zap',      'label' => 'Total Konsumsi', 'val' => $fmt($kpi['kwh_today']),  'unit' => ($kpi['kwh_today'] === NULL ? '' : 'kWh'), 'sub' => 'kWh hari ini — jumlah per panel'),
  array('icon' => 'gauge',    'label' => 'Beban Puncak',   'val' => $fmt($kpi['peak_kw']),    'unit' => ($kpi['peak_kw']   === NULL ? '' : 'kW'),  'sub' => 'kW terbesar hari ini'),
  array('icon' => 'activity', 'label' => 'Power Factor',   'val' => $fmt($kpi['avg_pf'], 2),  'unit' => ($kpi['avg_pf']    === NULL ? '' : 'pf'),  'sub' => 'rata-rata hari ini'),
  array('icon' => 'plug-zap', 'label' => 'Panel Online',   'val' => $on_txt,                  'unit' => 'unit',                                    'sub' => 'pembaruan ≤5 menit terakhir'),
);

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

<?php
$dp = isset($tarif_panel) ? $tarif_panel : array(
  'date' => '', 'total' => NULL, 'panels_n' => 0, 'panels' => array(), 'src' => 'none',
);
$dp_total   = $dp['total'];
$dp_has     = ($dp_total !== NULL && (int) $dp_total > 0);
$dp_panels  = isset($dp['panels']) ? $dp['panels'] : array();
$dp_keys    = array('lvmdp' => 'p1', 'lvmdp2' => 'p2', 'lvmdp3' => 'p3');
$dp_r       = 76.0;
$dp_c       = 2 * M_PI * $dp_r;
$dp_gap     = 3.5;
$dp_cum     = 0.0;
$dp_aria    = array();
foreach ($dp_panels as $p) {
  if (!empty($p['has_data'])) {
    $dp_aria[] = $p['name'] . ' ' . $dp_pct($p['pct']);
  }
}
$dp_aria_txt = 'Distribusi tarif hari ini: ' . implode(', ', $dp_aria);
if ($dp_has) {
  $dp_aria_txt .= '; total ' . $dp_rp($dp_total);
}
?>
<section id="tarifdist" class="reveal rounded-lg border border-ems-line bg-white" style="--reveal-delay: 160ms" aria-labelledby="tarifdist-title">
  <div class="flex flex-col gap-4 p-4 md:p-6">
    <div class="flex flex-wrap items-start gap-3">
      <div class="flex min-w-0 flex-col gap-1">
        <h2 id="tarifdist-title" class="truncate text-sm font-semibold">Distribusi Tarif Hari Ini</h2>
        <p class="max-w-prose text-xs text-ems-muted">Total biaya energi <?= html_escape($dp['date']) ?>, dibagi per panel (WIB).</p>
      </div>
      <?php if ($dp_has): ?>
        <span class="ml-auto shrink-0 rounded-full bg-ems-ambersoft px-2.5 py-1 text-[11px] font-semibold text-ems-amberdeep">Sumber DB</span>
      <?php endif; ?>
    </div>

    <?php if (!$dp_has): ?>
      <?php $this->load->view('partials/empty_state', array(
        'icon'    => 'pie-chart',
        'title'   => 'Belum ada tarif hari ini',
        'desc'    => 'Data muncul setelah panel mengirim pembacaan kWh hari ini.',
        'note'    => 'Panel tanpa data tidak dihitung dalam distribusi.',
        'animate' => FALSE,
      )); ?>
    <?php else: ?>
    <div class="grid grid-cols-1 items-center gap-6 md:grid-cols-[minmax(200px,260px)_minmax(0,1fr)]">
      <div class="relative mx-auto w-full max-w-[240px] sm:max-w-[260px]">
        <svg viewBox="0 0 200 200" class="block w-full" role="img" aria-label="<?= html_escape($dp_aria_txt) ?>">
          <circle class="tarifdist-track" cx="100" cy="100" r="<?= $dp_r ?>" />
          <?php foreach ($dp_panels as $p):
            if (empty($p['has_data']) || $p['pct'] === NULL) { continue; }
            $frac = ((float) $p['pct']) / 100.0;
            $len  = max(0.0, $frac * $dp_c - $dp_gap);
            $off  = -$dp_cum * $dp_c - $dp_gap / 2.0;
            $key  = isset($dp_keys[$p['code']]) ? $dp_keys[$p['code']] : 'p1';
            $dp_cum += $frac;
          ?>
          <circle class="tarifdist-seg tarifdist-seg--<?= $key ?>"
            data-donut-seg="<?= $key ?>"
            cx="100" cy="100" r="<?= $dp_r ?>"
            transform="rotate(-90 100 100)"
            stroke-dasharray="<?= number_format($len, 3, '.', '') ?> <?= number_format($dp_c, 3, '.', '') ?>"
            stroke-dashoffset="<?= number_format($off, 3, '.', '') ?>"
            aria-hidden="true" focusable="false" />
          <?php endforeach; ?>
        </svg>
        <div class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center px-6 text-center">
          <span data-donut-label class="truncate text-[11px] font-medium text-ems-muted">Total hari ini</span>
          <span data-donut-value class="mt-0.5 text-lg font-semibold tabular-nums text-ems-ink sm:text-xl"><?= $dp_rp($dp_total) ?></span>
          <span data-donut-meta class="mt-0.5 text-[11px] text-ems-muted"><?= (int) $dp['panels_n'] ?> panel</span>
        </div>
      </div>

      <ul class="flex min-w-0 flex-col divide-y divide-ems-line" aria-label="Rincian tarif per panel">
        <?php foreach ($dp_panels as $p):
          $key = isset($dp_keys[$p['code']]) ? $dp_keys[$p['code']] : 'p1';
          $has = !empty($p['has_data']);
        ?>
        <li>
          <?php if ($has): ?>
          <button type="button"
            data-donut-row="<?= $key ?>"
            data-donut-name="<?= html_escape($p['name']) ?>"
            data-donut-rp="<?= html_escape($dp_rp($p['rp'])) ?>"
            data-donut-pct="<?= html_escape($dp_pct($p['pct'])) ?>"
            aria-pressed="false"
            class="tarifdist-row flex w-full items-center gap-3 rounded-lg px-2 py-3 text-left outline-offset-2 focus-visible:outline-2 focus-visible:outline-ems-amber">
            <span class="tarifdist-swatch tarifdist-swatch--<?= $key ?> size-2.5 shrink-0 rounded-full" aria-hidden="true"></span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-medium"><?= html_escape($p['name']) ?></span>
              <span class="block text-xs text-ems-muted"><?= $dp_pct($p['pct']) ?> dari total</span>
            </span>
            <span class="shrink-0 whitespace-nowrap text-right">
              <span class="block text-sm font-semibold tabular-nums"><?= $dp_rp($p['rp']) ?></span>
              <span class="block text-xs tabular-nums text-ems-muted"><?= $dp_kwh($p['kwh']) ?> kWh</span>
            </span>
          </button>
          <?php else: ?>
          <div class="flex items-center gap-3 px-2 py-3 text-ems-muted">
            <span class="tarifdist-swatch tarifdist-swatch--<?= $key ?> size-2.5 shrink-0 rounded-full opacity-40" aria-hidden="true"></span>
            <span class="min-w-0 flex-1">
              <span class="block truncate text-sm font-medium"><?= html_escape($p['name']) ?></span>
              <span class="block text-xs">Belum ada data hari ini</span>
            </span>
            <span class="shrink-0 text-right text-sm font-semibold tabular-nums">—</span>
          </div>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <p class="max-w-prose text-[11px] leading-relaxed text-ems-muted">Dihitung dari selisih kWh hari ini dikali tarif aktif. Persentase mengikuti total panel yang memiliki data.</p>
    <?php endif; ?>
  </div>
</section>

<div class="grid grid-cols-1 gap-4">
  <!-- Ringkasan sistem: per-panel, nilai terbaru dari DB -->
  <div class="reveal rounded-lg border border-ems-line bg-white" style="--reveal-delay: 200ms">
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

  </div>

<script src="<?= base_url('assets/js/dashboard-tarif-donut.js') ?>"></script>
