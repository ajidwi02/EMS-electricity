<?php defined('BASEPATH') OR exit('No direct script access allowed');
// Partial: empty state ala shadcn Empty.
// Variabel: $icon (lucide), $title, $desc, opsional $note + $animate (bool float).
$animate = !empty($animate);
?>
<div class="flex flex-col items-center gap-3 py-12 text-center">
  <div class="flex size-12 items-center justify-center rounded-full bg-[#EFF0ED] <?= $animate ? 'float-slow' : '' ?>">
    <i data-lucide="<?= html_escape($icon) ?>" class="size-6 text-ems-muted"></i>
  </div>
  <h3 class="font-semibold text-ems-ink"><?= html_escape($title) ?></h3>
  <p class="max-w-sm text-sm text-ems-muted"><?= html_escape($desc) ?></p>
  <?php if (!empty($note)): ?>
    <p class="max-w-sm text-xs text-ems-muted/80"><?= html_escape($note) ?></p>
  <?php endif; ?>
</div>
