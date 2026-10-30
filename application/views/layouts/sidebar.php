<?php defined('BASEPATH') OR exit('No direct script access allowed');
$active = isset($active_menu) ? $active_menu : '';

function ems_nav_class($key, $active) {
  $base = 'nav-item flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ems-amber ';
  return $base . ($key === $active
    ? 'is-active bg-graphite-800 text-white'
    : 'text-[#9AA1AD] hover:bg-graphite-800 hover:text-white');
}
?>
<aside id="sidebar"
  aria-label="Navigasi utama"
  class="fixed inset-y-0 left-0 z-50 flex w-[260px] -translate-x-full flex-col gap-6 overflow-y-auto bg-graphite-950 px-4 py-5 text-white lg:translate-x-0">

  <div class="flex items-center gap-2">
    <a href="<?= site_url('dashboard') ?>" aria-label="Ke Dashboard"
      class="flex min-w-0 flex-1 items-center rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ems-amber">
      <img src="<?= base_url('assets/img/logo-1.png') ?>" alt="Apparel One Indonesia"
        class="h-10 w-auto max-w-[170px] object-contain lg:h-11" loading="eager">
    </a>
    <button id="btn-close-sidebar" type="button" aria-label="Tutup menu"
      class="ml-auto flex size-10 items-center justify-center rounded-lg text-[#9AA1AD] hover:bg-graphite-800 hover:text-white lg:hidden">
      <i data-lucide="x" class="size-5"></i>
    </button>
  </div>

  <nav class="flex flex-col gap-5">
    <div class="flex flex-col gap-1">
      <p class="px-3 text-[11px] font-medium uppercase tracking-wider text-[#9AA1AD]">Menu</p>
      <a data-nav href="<?= site_url('dashboard') ?>" class="<?= ems_nav_class('dashboard', $active) ?>">
        <i data-lucide="layout-dashboard" class="size-5 shrink-0"></i>
        <span class="truncate">Dashboard</span>
      </a>
    </div>

    <div class="flex flex-col gap-1">
      <p class="px-3 text-[11px] font-medium uppercase tracking-wider text-[#9AA1AD]">Monitoring</p>
      <a data-nav href="<?= site_url('lvmdp') ?>" class="<?= ems_nav_class('lvmdp', $active) ?>">
        <i data-lucide="zap" class="size-5 shrink-0"></i>
        <span class="truncate">Panel 1</span>
      </a>
      <a data-nav href="<?= site_url('puma') ?>" class="<?= ems_nav_class('puma', $active) ?>">
        <i data-lucide="activity" class="size-5 shrink-0"></i>
        <span class="truncate">Panel 2</span>
      </a>
      <a data-nav href="<?= site_url('nagai') ?>" class="<?= ems_nav_class('nagai', $active) ?>">
        <i data-lucide="boxes" class="size-5 shrink-0"></i>
        <span class="truncate">Panel 3</span>
      </a>
    </div>

    <div class="flex flex-col gap-1">
      <div class="mx-3 my-1 h-px bg-[#262C33]" role="separator"></div>
      <p class="px-3 text-[11px] font-medium uppercase tracking-wider text-[#9AA1AD]">System</p>
      <a data-nav href="<?= site_url('export-data') ?>" class="<?= ems_nav_class('export', $active) ?>">
        <i data-lucide="file-down" class="size-5 shrink-0"></i>
        <span class="truncate">Export Data</span>
      </a>
    </div>
  </nav>

</aside>
