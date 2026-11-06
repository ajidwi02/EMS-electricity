<?php defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('ems_num')) {
  function ems_num($v, $dec = 1)
  {
    return ($v === NULL || $v === '') ? NULL : number_format((float) $v, $dec, '.', '');
  }
}

if (!function_exists('ems_rp')) {
  // CP-073: satu baris = satu panel per hari; rupiah id-ID; tanpa "Rp" (sudah di label kolom).
  function ems_rp($v)
  {
    return ($v === NULL || $v === '') ? NULL : number_format((float) $v, 0, ',', '.');
  }
}

$fmtColTime = function ($s) {
  $tz = new DateTimeZone('Asia/Jakarta');
  $dt = DateTime::createFromFormat('Y-m-d H:i', (string) $s, $tz);
  if (!$dt instanceof DateTime || $dt->format('Y-m-d H:i') !== (string) $s) {
    return html_escape($s);
  }
  $bulans = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
                  7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
  $lbl = $dt->format('j') . ' ' . $bulans[(int) $dt->format('n')] . ' ' . $dt->format('Y') . ' · ' . $dt->format('H:i');
  return html_escape($lbl);
};

// --- helpers filter periode (4a-2) ----------------------------------------
$fmtDate = function ($ymd) {
  $b = array(1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
             7 => 'Jul', 8 => 'Agt', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des');
  $p = explode('-', $ymd);
  return $p[2] . ' ' . $b[(int) $p[1]] . ' ' . $p[0];   // 20 Okt 2026
};

$chips = array(
  'today'      => 'Hari ini',
  'week'       => 'Minggu ini',
  'month'      => 'Bulan ini',
  'prev-month' => 'Bulan lalu',
  'custom'     => 'Custom',
);

$range_txt = ($from === $to) ? $fmtDate($from) : $fmtDate($from) . ' – ' . $fmtDate($to);

// prefix query pagination: selalu bawa filter aktif (custom -> bawa rentang; panel -> bawa panel terpilih)
$pf = isset($panel_filter) ? $panel_filter : '';
$fq = array('filter' => $filter_key);
if ($filter_key === 'custom') {
  $fq['from'] = $from;
  $fq['to']   = $to;
}
if ($pf !== '') {
  $fq['panel'] = $pf;
}
$pg_url = function ($n) use ($fq) {
  $q = $fq;
  $q['page'] = (int) $n;
  return site_url('export-data') . '?' . http_build_query($q);
};

// CP-075: tombol Export DIPINDAH dari topbar ke dalam kartu tabel Log Data
// (header kartu, kiri teks "Menampilkan…") — akses lebih dekat ke datanya.
// Page header kini dipanggil TANPA 'action' untuk kedua tab.
$tab = (isset($tab) && $tab === 'tarif') ? 'tarif' : 'log';
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle));

// --- Pagination list (algoritma deterministik CP-013, tetap) --------------
$pg_list = array();
foreach (array(1, $page - 1, $page, $page + 1, $pages) as $p) {
  if ($p >= 1 && $p <= $pages) { $pg_list[$p] = TRUE; }
}
$pg_list = array_keys($pg_list);
$pg_show = array();
$reset = -10;
foreach ($pg_list as $p) {
  if ($reset >= 0 && $p - $reset > 1) { $pg_show[] = 'gap'; }
  $pg_show[] = $p;
  $reset = $p;
}

$fmt = function ($v, $dec = 1) {
  return ($v === NULL ? '<span class="text-ems-muted">—</span>' : html_escape(ems_num($v, $dec)));
};

$empty_desc = ($total === 0 && $filter_key === 'custom')
  ? 'Tidak ada data pada rentang ' . $fmtDate($from) . ' – ' . $fmtDate($to) . '; coba rentang lain.'
  : 'Tidak ada pembacaan pada periode ini — pastikan worker pembacaan berjalan untuk merekam data baru.';
?>
<div class="flex flex-col gap-6">
  <!-- CP-073: sub menu halaman Export Data (segmented; default Log Data) -->
  <nav aria-label="Sub menu export data" role="group" class="inline-flex flex-wrap items-center gap-0.5 self-start rounded-lg border border-ems-line bg-white p-1">
    <a href="<?= site_url('export-data') ?>" class="inline-flex h-8 items-center rounded-md px-4 text-sm font-medium <?= ($tab === 'log') ? 'bg-graphite-950 text-white' : 'text-ems-ink hover:bg-[#F7F8F6]' ?>" <?= ($tab === 'log') ? 'aria-current="page"' : '' ?>>Log Data</a>
    <a href="<?= site_url('export-data?tab=tarif') ?>" class="inline-flex h-8 items-center rounded-md px-4 text-sm font-medium <?= ($tab === 'tarif') ? 'bg-graphite-950 text-white' : 'text-ems-ink hover:bg-[#F7F8F6]' ?>" <?= ($tab === 'tarif') ? 'aria-current="page"' : '' ?>>Tarif Konsumsi</a>
  </nav>

  <?php if ($tab === 'log'): ?>
  <!-- Kartu tabel historis -->
  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:items-center md:px-6">
      <div class="flex min-w-0 flex-col">
        <h2 class="truncate text-sm font-semibold">Historis Pembacaan</h2>
        <p class="truncate text-xs text-ems-muted">Zona waktu WIB (Asia/Jakarta)</p>
      </div>
      <div class="ml-2 flex items-center gap-1 shrink-0" role="group" aria-label="Filter panel">
        <?php $pf = isset($panel_filter) ? $panel_filter : ''; ?>
        <?php
        // Bawa rentang tanggal kustom saat berpindah panel, agar filter tanggal tetap terpelihara
        $date_qs = ($filter_key === 'custom') ? '&from=' . urlencode($from) . '&to=' . urlencode($to) : '';
        ?>
        <a href="<?= site_url('export-data?filter=' . urlencode($filter_key) . '&panel=' . $date_qs) ?>" class="inline-flex h-7 items-center rounded-md px-2.5 text-[11px] font-medium <?= ($pf === '') ? 'bg-graphite-950 text-white' : 'bg-white text-ems-ink border border-ems-line hover:bg-[#F7F8F6]' ?>">Semua</a>
        <a href="<?= site_url('export-data?filter=' . urlencode($filter_key) . '&panel=' . urlencode('Panel 1') . $date_qs) ?>" class="inline-flex h-7 items-center rounded-md px-2.5 text-[11px] font-medium <?= ($pf === 'Panel 1') ? 'bg-graphite-950 text-white' : 'bg-white text-ems-ink border border-ems-line hover:bg-[#F7F8F6]' ?>">Panel 1</a>
        <a href="<?= site_url('export-data?filter=' . urlencode($filter_key) . '&panel=' . urlencode('Panel 2') . $date_qs) ?>" class="inline-flex h-7 items-center rounded-md px-2.5 text-[11px] font-medium <?= ($pf === 'Panel 2') ? 'bg-graphite-950 text-white' : 'bg-white text-ems-ink border border-ems-line hover:bg-[#F7F8F6]' ?>">Panel 2</a>
        <a href="<?= site_url('export-data?filter=' . urlencode($filter_key) . '&panel=' . urlencode('Panel 3') . $date_qs) ?>" class="inline-flex h-7 items-center rounded-md px-2.5 text-[11px] font-medium <?= ($pf === 'Panel 3') ? 'bg-graphite-950 text-white' : 'bg-white text-ems-ink border border-ems-line hover:bg-[#F7F8F6]' ?>">Panel 3</a>
      </div>
      <!-- CP-075: kawasan kanan header kartu — tombol Export (kiri) + penghitung baris (kanan) -->
      <div class="flex flex-wrap items-center gap-3 sm:ml-auto">
        <button type="button" id="btnExport" title="Unduh data" class="flex items-center gap-2 rounded-lg bg-graphite-950 px-4 py-2 text-sm font-medium text-white hover:bg-graphite-800"><i data-lucide="download" data-icon="inline-start" class="size-4"></i>Export</button>
        <span class="shrink-0 text-xs tabular-nums text-ems-muted">
          Menampilkan <span class="font-semibold text-ems-ink"><?= (int) $floor_num ?>–<?= (int) $top_num ?></span>
          dari <span class="font-semibold text-ems-ink"><?= (int) $total ?></span> baris
        </span>
      </div>
    </div>

    <!-- Toolbar filter periode (fase 4a-2) -->
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center md:px-6">
      <span class="shrink-0 text-xs font-medium text-ems-muted">Periode</span>
      <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter periode">
        <?php foreach ($chips as $key => $lbl): ?>
          <?php if ($key === $filter_key): ?>
            <span class="inline-flex h-8 items-center rounded-lg bg-graphite-950 px-3 text-xs font-medium text-white" aria-current="page"><?= html_escape($lbl) ?></span>
          <?php else: ?>
            <a href="<?= site_url('export-data') . '?filter=' . $key . ($pf !== '' ? '&panel=' . urlencode($pf) : '') ?>" class="inline-flex h-8 items-center rounded-lg border border-ems-line bg-white px-3 text-xs text-ems-ink hover:bg-[#F7F8F6]"><?= html_escape($lbl) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($custom_warning !== ''): ?>
          <span class="inline-flex items-center rounded-full bg-ems-ambersoft px-2.5 py-1 text-[11px] font-semibold text-ems-amberdeep"><?= html_escape($custom_warning) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($custom_open): ?>
        <form method="get" action="<?= site_url('export-data') ?>" class="flex flex-wrap items-center gap-2 sm:ml-auto" aria-label="Rentang tanggal kustom">
          <input type="hidden" name="filter" value="custom">
          <?php if ($pf !== ''): ?><input type="hidden" name="panel" value="<?= html_escape($pf) ?>"><?php endif; ?>
          <input type="date" name="from" value="<?= html_escape($from) ?>" required aria-label="Tanggal mulai" class="h-8 rounded-lg border border-ems-line bg-white px-2 text-xs text-ems-ink">
          <span class="text-xs text-ems-muted">s/d</span>
          <input type="date" name="to" value="<?= html_escape($to) ?>" required aria-label="Tanggal akhir" class="h-8 rounded-lg border border-ems-line bg-white px-2 text-xs text-ems-ink">
          <button type="submit" class="inline-flex h-8 items-center rounded-lg bg-graphite-950 px-3 text-xs font-medium text-white hover:bg-graphite-800">Terapkan</button>
        </form>
      <?php endif; ?>
      <span class="text-xs tabular-nums text-ems-muted sm:ml-auto"><?= html_escape($range_txt) ?></span>
    </div>

    <?php if (empty($rows)): ?>
      <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'table',
        'title' => 'Belum ada data pembacaan',
        'desc'  => $empty_desc,
      )); ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[1120px] text-sm" role="table">
          <thead class="bg-[#EFF0ED]">
            <tr class="border-b border-ems-line">
              <th class="px-3 py-2.5 text-left font-semibold"><span class="block">Waktu Catat</span><span class="block text-[11px] font-normal text-ems-muted">WIB · menit</span></th>
              <th class="px-3 py-2.5 text-left font-semibold"><span class="block">Panel</span><span class="block text-[11px] font-normal text-ems-muted">nama tampil</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tegangan R-N</span><span class="block text-[11px] font-normal text-ems-muted">Volt (V)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tegangan S-N</span><span class="block text-[11px] font-normal text-ems-muted">Volt (V)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tegangan T-N</span><span class="block text-[11px] font-normal text-ems-muted">Volt (V)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tegangan R-S</span><span class="block text-[11px] font-normal text-ems-muted">Volt (V)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tegangan S-T</span><span class="block text-[11px] font-normal text-ems-muted">Volt (V)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tegangan R-T</span><span class="block text-[11px] font-normal text-ems-muted">Volt (V)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Arus R</span><span class="block text-[11px] font-normal text-ems-muted">Ampere (A)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Arus S</span><span class="block text-[11px] font-normal text-ems-muted">Ampere (A)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Arus T</span><span class="block text-[11px] font-normal text-ems-muted">Ampere (A)</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">PF</span><span class="block text-[11px] font-normal text-ems-muted">power factor</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Daya Aktif</span><span class="block text-[11px] font-normal text-ems-muted">kW</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Energi</span><span class="block text-[11px] font-normal text-ems-muted">kWh</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
            <tr class="border-b border-ems-line/70 last:border-0 hover:bg-[#F7F8F6]">
              <td class="whitespace-nowrap px-3 py-2 tabular-nums"><?= $fmtColTime($row['time']) ?></td>
              <td class="whitespace-nowrap px-3 py-2"><?= html_escape($row['panel']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['v_r']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['v_s']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['v_t']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['v_rs']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['v_st']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['v_rt']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['i_r']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['i_s']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['i_t']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['pf'], 2) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['kw']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['kwh'], 1) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <!-- Strip bawah: meta + pagination -->
    <div class="flex flex-col gap-3 border-t border-ems-line px-4 py-3 md:flex-row md:items-center md:justify-between md:px-6">
      <p class="text-xs text-ems-muted">
        Data dari database <span class="font-semibold text-ems-ink">ems</span> · maksimal <span class="font-semibold text-ems-ink"><?= (int) $per_page ?> baris/halaman</span>.
      </p>
      <nav class="flex flex-wrap items-center gap-1.5" aria-label="Pagination halaman">
        <!-- tombol prev -->
        <?php $prev = max(1, $page - 1); if ($page <= 1): ?>
          <span class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-lg border border-ems-line/60 bg-white text-ems-muted/60" aria-disabled="true"><i data-lucide="chevron-left" class="size-4"></i></span>
        <?php else: ?>
          <a href="<?= html_escape($pg_url($prev)) ?>" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-ems-line bg-white text-ems-ink hover:bg-[#F7F8F6]" aria-label="Halaman sebelumnya"><i data-lucide="chevron-left" class="size-4"></i></a>
        <?php endif; ?>

        <!-- chip nomor halaman -->
        <?php foreach ($pg_show as $p): ?>
          <?php if ($p === 'gap'): ?>
            <span class="inline-flex h-8 min-w-8 items-center justify-center text-ems-muted">…</span>
          <?php elseif ($p === $page): ?>
            <span class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg bg-graphite-950 text-sm font-medium text-white" aria-current="page"><?= (int) $p ?></span>
          <?php else: ?>
            <a href="<?= html_escape($pg_url($p)) ?>" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-ems-line bg-white text-sm text-ems-ink hover:bg-[#F7F8F6]"><?= (int) $p ?></a>
          <?php endif; ?>
        <?php endforeach; ?>

        <!-- tombol next -->
        <?php $next = min($pages, $page + 1); if ($page >= $pages): ?>
          <span class="inline-flex h-8 min-w-8 cursor-not-allowed items-center justify-center rounded-lg border border-ems-line/60 bg-white text-ems-muted/60" aria-disabled="true"><i data-lucide="chevron-right" class="size-4"></i></span>
        <?php else: ?>
          <a href="<?= html_escape($pg_url($next)) ?>" class="inline-flex h-8 min-w-8 items-center justify-center rounded-lg border border-ems-line bg-white text-ems-ink hover:bg-[#F7F8F6]" aria-label="Halaman berikutnya"><i data-lucide="chevron-right" class="size-4"></i></a>
        <?php endif; ?>
      </nav>
    </div>
  </div>
  <?php else: ?>
  <!-- CP-073: kartu "Tarif Konsumsi" — tabel polos sementara (tanpa filter & tanpa Export) -->
  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex flex-col gap-1 border-b border-ems-line px-4 py-3 md:px-6">
      <h2 class="truncate text-sm font-semibold">Tarif Konsumsi</h2>
      <p class="truncate text-xs text-ems-muted">Rekap harian biaya konsumsi listrik per panel (WIB)</p>
    </div>

    <?php if (empty($tarif_rows)): ?>
      <?php $this->load->view('partials/empty_state', array(
        'icon'  => 'wallet',
        'title' => 'Belum ada rekap tarif konsumsi',
        'desc'  => 'Rekap harian terisi otomatis oleh pencatatan harian — satu baris per panel per hari (WIB) begitu datanya ada.',
      )); ?>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="w-full min-w-[880px] text-sm" role="table">
          <thead class="bg-[#EFF0ED]">
            <tr class="border-b border-ems-line">
              <th class="px-3 py-2.5 text-left font-semibold"><span class="block">Tanggal</span><span class="block text-[11px] font-normal text-ems-muted">WIB</span></th>
              <th class="px-3 py-2.5 text-left font-semibold"><span class="block">Lokasi</span><span class="block text-[11px] font-normal text-ems-muted">panel</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">LWBP</span><span class="block text-[11px] font-normal text-ems-muted">KWh</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">WBP</span><span class="block text-[11px] font-normal text-ems-muted">KWh</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tarif LWBP</span><span class="block text-[11px] font-normal text-ems-muted">Rp</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Tarif WBP</span><span class="block text-[11px] font-normal text-ems-muted">Rp</span></th>
              <th class="px-3 py-2.5 text-right font-semibold"><span class="block">Total Tarif</span><span class="block text-[11px] font-normal text-ems-muted">Rp</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($tarif_rows as $row): ?>
            <tr class="border-b border-ems-line/70 last:border-0 hover:bg-[#F7F8F6]">
              <td class="whitespace-nowrap px-3 py-2 tabular-nums"><?= $fmtDate($row['tanggal']) ?><?php if ((int) $row['is_final'] === 0): ?><span class="ml-1.5 inline-flex items-center rounded-full bg-ems-ambersoft px-1.5 py-0.5 text-[10px] font-medium text-ems-amberdeep" title="Hari berjalan — angka masih bisa berubah sampai tengah malam">Estimasi</span><?php endif; ?></td>
              <td class="whitespace-nowrap px-3 py-2"><?= html_escape($row['panel']) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['kwh_lwbp'], 2) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= $fmt($row['kwh_wbp'], 2) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= ($row['biaya_lwbp'] === NULL) ? '<span class="text-ems-muted">—</span>' : html_escape(ems_rp($row['biaya_lwbp'])) ?></td>
              <td class="px-3 py-2 text-right tabular-nums"><?= ($row['biaya_wbp'] === NULL) ? '<span class="text-ems-muted">—</span>' : html_escape(ems_rp($row['biaya_wbp'])) ?></td>
              <td class="px-3 py-2 text-right font-medium tabular-nums"><?= ($row['biaya_total'] === NULL) ? '<span class="text-ems-muted">—</span>' : html_escape(ems_rp($row['biaya_total'])) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <div class="border-t border-ems-line px-4 py-3 md:px-6">
      <p class="text-xs text-ems-muted">
        Data dari database <span class="font-semibold text-ems-ink">ems</span> · satu baris = satu panel per hari (WIB)<?= (count($tarif_rows) >= 200) ? ' · menampilkan 200 rekap terbaru' : '' ?>.
      </p>
    </div>
  </div>
  <?php endif; ?>

  <?php if ($tab === 'log'): ?>
  <!-- Modal pemilihan format export -->
  <div id="exportModal" class="fixed inset-0 z-50 hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="absolute inset-0 bg-black/40" onclick="document.getElementById('exportModal').classList.add('hidden')"></div>
    <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 bg-white rounded-lg shadow p-6 w-96 border border-ems-line">
      <h3 class="text-sm font-semibold text-ems-ink mb-4">Unduh Data Export</h3>
      <form id="formExport" method="get" action="<?= site_url('export-data/download') ?>" class="flex flex-col gap-3">
        <input type="hidden" name="filter" value="<?= html_escape($filter_key) ?>">
        <input type="hidden" name="from" value="<?= html_escape($from) ?>">
        <input type="hidden" name="to" value="<?= html_escape($to) ?>">
        <input type="hidden" name="panel" value="<?= html_escape($pf) ?>">
        <div>
          <label class="block text-[11px] text-ems-muted mb-2">Format file</label>
          <label class="flex items-center gap-2 text-xs text-ems-ink"><input type="radio" name="format" value="csv" checked> CSV (.csv)</label>
          <label class="flex items-center gap-2 text-xs text-ems-ink"><input type="radio" name="format" value="xlsx"> Excel (.xlsx)</label>
          <label class="flex items-center gap-2 text-xs text-ems-ink"><input type="radio" name="format" value="pdf"> PDF (.pdf)</label>
        </div>
        <div class="flex justify-end gap-2 mt-5 pt-3 border-t border-ems-line/50">
          <button type="button" onclick="document.getElementById('exportModal').classList.add('hidden')" class="px-3 py-1.5 text-xs text-ems-ink border border-ems-line rounded hover:bg-[#F7F8F6]">Batal</button>
          <button type="submit" onclick="document.getElementById('exportModal').classList.add('hidden')" class="px-3 py-1.5 text-xs text-white bg-graphite-950 rounded hover:bg-graphite-800">Unduh</button>
        </div>
      </form>
    </div>
  </div>
  <script>
    document.getElementById('btnExport').addEventListener('click', function () {
      document.getElementById('exportModal').classList.remove('hidden');
    });
  </script>
  <?php endif; ?>
</div>
