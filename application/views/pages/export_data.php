<?php defined('BASEPATH') OR exit('No direct script access allowed');

if (!function_exists('ems_num')) {
  function ems_num($v, $dec = 1)
  {
    return ($v === NULL || $v === '') ? NULL : number_format((float) $v, $dec, '.', '');
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

// prefix query pagination: selalu bawa filter aktif (custom -> bawa rentang)
$fq = array('filter' => $filter_key);
if ($filter_key === 'custom') {
  $fq['from'] = $from;
  $fq['to']   = $to;
}
$pg_url = function ($n) use ($fq) {
  $q = $fq;
  $q['page'] = (int) $n;
  return site_url('export-data') . '?' . http_build_query($q);
};

// Tombol Export tetap disabled — unduh CSV/Excel = fase 4c berikutnya.
$action = '<button type="button" disabled title="Segera hadir" class="flex cursor-not-allowed items-center gap-2 rounded-lg bg-graphite-950 px-4 py-2 text-sm font-medium text-white opacity-50"><i data-lucide="download" data-icon="inline-start" class="size-4"></i>Export</button>';
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle, 'action' => $action));

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
  <!-- Kartu tabel historis -->
  <div class="rounded-lg border border-ems-line bg-white">
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:items-center md:px-6">
      <div class="flex min-w-0 flex-col">
        <h2 class="truncate text-sm font-semibold">Historis Pembacaan</h2>
        <p class="truncate text-xs text-ems-muted">Zona waktu WIB (Asia/Jakarta)</p>
      </div>
      <span class="ml-auto shrink-0 text-xs tabular-nums text-ems-muted">
        Menampilkan <span class="font-semibold text-ems-ink"><?= (int) $floor_num ?>–<?= (int) $top_num ?></span>
        dari <span class="font-semibold text-ems-ink"><?= (int) $total ?></span> baris
      </span>
    </div>

    <!-- Toolbar filter periode (fase 4a-2) -->
    <div class="flex flex-col gap-2 border-b border-ems-line px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center md:px-6">
      <span class="shrink-0 text-xs font-medium text-ems-muted">Periode</span>
      <div class="flex flex-wrap items-center gap-1.5" role="group" aria-label="Filter periode">
        <?php foreach ($chips as $key => $lbl): ?>
          <?php if ($key === $filter_key): ?>
            <span class="inline-flex h-8 items-center rounded-lg bg-graphite-950 px-3 text-xs font-medium text-white" aria-current="page"><?= html_escape($lbl) ?></span>
          <?php else: ?>
            <a href="<?= site_url('export-data') . '?filter=' . $key ?>" class="inline-flex h-8 items-center rounded-lg border border-ems-line bg-white px-3 text-xs text-ems-ink hover:bg-[#F7F8F6]"><?= html_escape($lbl) ?></a>
          <?php endif; ?>
        <?php endforeach; ?>
        <?php if ($custom_warning !== ''): ?>
          <span class="inline-flex items-center rounded-full bg-ems-ambersoft px-2.5 py-1 text-[11px] font-semibold text-ems-amberdeep"><?= html_escape($custom_warning) ?></span>
        <?php endif; ?>
      </div>
      <?php if ($custom_open): ?>
        <form method="get" action="<?= site_url('export-data') ?>" class="flex flex-wrap items-center gap-2 sm:ml-auto" aria-label="Rentang tanggal kustom">
          <input type="hidden" name="filter" value="custom">
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
</div>
