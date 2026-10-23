<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<header class="sticky top-0 z-30 flex h-16 items-center gap-3 border-b border-ems-line bg-white/85 px-4 backdrop-blur md:px-6 lg:px-8">
  <button id="btn-open-sidebar" type="button" aria-label="Buka menu"
    class="flex size-10 items-center justify-center rounded-lg text-ems-ink hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ems-amber lg:hidden">
    <i data-lucide="menu" class="size-5"></i>
  </button>

  <div class="flex min-w-0 flex-col">
    <div class="flex min-w-0 items-center gap-2 text-sm">
      <span class="hidden shrink-0 font-medium text-ems-muted sm:inline">EMS</span>
      <span class="hidden shrink-0 text-ems-line sm:inline">/</span>
      <span class="truncate font-semibold text-ems-ink"><?= html_escape(isset($title) ? $title : 'EMS') ?></span>
    </div>
    <?php if (!empty($subtitle)): ?>
      <p class="hidden truncate text-xs text-ems-muted md:block"><?= html_escape($subtitle) ?></p>
    <?php endif; ?>
  </div>

  <div class="ml-auto flex items-center gap-3">
    <?php
    // Jam Jakarta (server-render awal, lalu di-tick tiap detik via app.js).
    $ems_now = new DateTime('now', new DateTimeZone('Asia/Jakarta'));
    $ems_days = array('Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu');
    $ems_months = array(1 => 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des');
    $ems_clock = $ems_days[(int) $ems_now->format('w')] . ', ' . $ems_now->format('j') . ' ' . $ems_months[(int) $ems_now->format('n')] . ' ' . $ems_now->format('Y')
      . ' • ' . $ems_now->format('H:i:s') . ' WIB';
    ?>
    <span id="ems-clock" title="Waktu Jakarta — data yang ditampilkan adalah data hari ini (realtime)"
      class="hidden items-center gap-2 rounded-full border border-ems-line bg-white px-3 py-1.5 text-xs font-medium tabular-nums text-ems-ink sm:flex">
      <span class="live-dot size-2 shrink-0 rounded-full bg-ems-ok"></span>
      <span id="ems-clock-text" aria-live="off"><?= html_escape($ems_clock) ?></span>
    </span>
  </div>
</header>
