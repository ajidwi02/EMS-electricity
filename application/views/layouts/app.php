<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= isset($title) ? html_escape($title).' — EMS' : 'EMS — Energy Management System' ?></title>
  <meta name="description" content="EMS — Energy Management System dashboard admin. Minimalist, modern, elegan untuk lingkungan industri.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = {
      theme: {
        extend: {
          fontFamily: { sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'] },
          borderRadius: { lg: 'var(--ems-radius)' },
          colors: {
            ems: {
              bg: '#F2F3F0',
              card: '#FFFFFF',
              ink: '#191C1F',
              muted: '#6B7280',
              line: '#E3E5E0',
              steel: '#9AA1AD',
              amber: '#EAA500',
              amberdeep: '#B97A0C',
              ambersoft: '#FFF6DE',
              ok: '#1A7F4D'
            },
            graphite: { 950: '#101316', 900: '#14171B', 800: '#1E2329', 700: '#2A3138' }
          }
        }
      }
    };
  </script>
  <link rel="stylesheet" href="<?= base_url('assets/css/app.css') ?>">
</head>
<body class="bg-ems-bg font-sans text-ems-ink antialiased">

  <div id="overlay" class="fixed inset-0 z-40 hidden bg-black/50 lg:hidden" aria-hidden="true"></div>

  <?php $this->load->view('layouts/sidebar', array('active_menu' => isset($active_menu) ? $active_menu : '')); ?>

  <div class="flex min-h-screen flex-col lg:pl-[260px]">

    <?php $this->load->view('layouts/topbar', array(
      'title'    => isset($title) ? $title : 'EMS',
      'subtitle' => isset($subtitle) ? $subtitle : '',
    )); ?>

    <main class="flex-1 p-4 md:p-6 lg:p-8">
      <div class="mx-auto flex w-full max-w-6xl flex-col gap-6">
        <?= isset($content) ? $content : '' ?>
      </div>
    </main>

    <?php $this->load->view('layouts/footer'); ?>

  </div>

  <script src="https://unpkg.com/lucide@latest"></script>
  <script src="<?= base_url('assets/js/app.js') ?>"></script>
</body>
</html>
