<?php defined('BASEPATH') OR exit('No direct script access allowed');
$this->load->view('partials/page_header', array('title' => $title, 'subtitle' => $subtitle));
?>
<div class="rounded-lg border border-ems-line bg-white">
  <div class="border-b border-ems-line px-4 py-3 md:px-6">
    <h2 class="truncate text-sm font-semibold">Panel Puma</h2>
  </div>
  <?php $this->load->view('partials/empty_state', array(
    'icon'  => 'activity',
    'title' => 'Belum ada data Puma',
    'desc'  => 'Parameter energi unit Puma akan tampil di sini.',
    'note'  => 'Modul akan diisi pada tahap berikutnya tanpa mengubah layout.',
  )); ?>
</div>
