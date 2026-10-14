<?php defined('BASEPATH') OR exit('No direct script access allowed');
$action = '<button type="button" disabled title="Segera hadir" class="flex cursor-not-allowed items-center gap-2 rounded-lg bg-graphite-950 px-4 py-2 text-sm font-medium text-white opacity-50"><i data-lucide="download" data-icon="inline-start" class="size-4"></i>Export</button>';
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle, 'action' => $action));
?>
<div class="rounded-lg border border-ems-line bg-white">
  <div class="border-b border-ems-line px-4 py-3 md:px-6">
    <h2 class="truncate text-sm font-semibold">Unduhan</h2>
  </div>
  <?php $this->load->view('partials/empty_state', array(
    'icon'  => 'file-down',
    'title' => 'Belum ada file untuk diunduh',
    'desc'  => 'Filter tanggal dan tombol unduh CSV/Excel akan tersedia di sini.',
    'note'  => 'Tombol Export aktif setelah modul historis selesai.',
  )); ?>
</div>
