<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between">
  <div class="flex min-w-0 flex-col gap-1">
    <h1 class="truncate text-xl font-semibold tracking-tight text-ems-ink lg:text-2xl"><?= html_escape($title) ?></h1>
    <?php if (!empty($subtitle)): ?>
      <p class="text-sm text-ems-muted"><?= html_escape($subtitle) ?></p>
    <?php endif; ?>
  </div>
  <?php if (!empty($action)): ?>
    <div class="flex shrink-0 items-center gap-2"><?= $action ?></div>
  <?php endif; ?>
</div>
