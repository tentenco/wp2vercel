<?php
$runner = WP2Vercel\Plugin::runner();
$build = $runner->start('full', ['deploy' => false]);
$ticks = 0;
$steps = [];
do {
    $runner->tick(0.15);
    $current = $runner->builds()->find($build->id);
    $steps[$current->step] = ($steps[$current->step] ?? 0) + 1;
    $ticks++;
} while ($current->isActive() && $ticks < 2000);
echo json_encode(['status' => $current->status, 'ticks' => $ticks, 'ticks_per_step' => $steps, 'files' => $current->state['manifest']['count'] ?? null, 'message' => $current->message]), "\n";
