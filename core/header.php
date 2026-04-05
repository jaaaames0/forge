<?php
/**
 * FORGE Global Header
 */
$current_page = basename(dirname($_SERVER['PHP_SELF']));
if ($current_page === 'forge') $current_page = 'home';

$nav_items = [
    'workout'   => ['label' => 'WORKOUT',   'mlabel' => 'LIFT',  'url' => '/forge/workout/',   'icon' => 'bi-lightning-charge-fill'],
    'progress'  => ['label' => 'PROGRESS',  'mlabel' => 'PROG',  'url' => '/forge/progress/',  'icon' => 'bi-graph-up-arrow'],
    'history'   => ['label' => 'HISTORY',   'mlabel' => 'HIST',  'url' => '/forge/history/',   'icon' => 'bi-clock-history'],
    'exercises' => ['label' => 'EXERCISES', 'mlabel' => 'EX',    'url' => '/forge/exercises/', 'icon' => 'bi-list-ul'],
    'profile'   => ['label' => 'PROFILE',   'mlabel' => 'ME',    'url' => '/forge/profile/',   'icon' => 'bi-person-fill'],
];
?>
<nav class="navbar navbar-dark sticky-top mb-4" style="border-bottom: 2px solid #333; background: #0a0a0a !important;">
    <div class="container-fluid px-4 d-flex align-items-center">
        <a class="navbar-brand fw-black flex-shrink-0" href="/forge/" style="font-weight: 900; letter-spacing: -1.5px; font-size: 1.5rem;">
            <span style="color: #FF5722;">FORGE</span><span class="text-muted" style="font-size: 0.8rem; letter-spacing: 0;">_v1</span>
        </a>

        <!-- Mobile nav: single line, icon above label, no toggle needed -->
        <div class="d-flex d-lg-none align-items-stretch ms-auto" style="flex: 1; justify-content: space-evenly;">
            <?php foreach ($nav_items as $key => $item):
                $is_active_nav = ($current_page === $key || strpos($_SERVER['PHP_SELF'], $item['url']) !== false);
            ?>
            <a class="nav-link forge-nav-link d-flex flex-column align-items-center justify-content-center px-1 <?= $is_active_nav ? 'active' : '' ?>"
               href="<?= $item['url'] ?>" style="min-width: 36px; padding-top: 6px; padding-bottom: 6px;">
                <i class="bi <?= $item['icon'] ?>" style="font-size: 1.2rem; line-height: 1; display: block;"></i>
                <span style="font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0; font-weight: 700; line-height: 1.3; margin-top: 3px;"><?= $item['mlabel'] ?></span>
            </a>
            <?php endforeach; ?>
            <a class="nav-link forge-nav-link d-flex flex-column align-items-center justify-content-center px-1"
               href="/spence/" style="min-width: 36px; padding-top: 6px; padding-bottom: 6px; opacity: 0.4;">
                <i class="bi bi-box-arrow-up-right" style="font-size: 1.2rem; line-height: 1; display: block;"></i>
                <span style="font-size: 0.58rem; text-transform: uppercase; letter-spacing: 0; font-weight: 700; line-height: 1.3; margin-top: 3px;">SPNC</span>
            </a>
        </div>

        <!-- Desktop nav: icon + text links -->
        <div class="d-none d-lg-flex ms-auto gap-2">
            <?php foreach ($nav_items as $key => $item):
                $is_active_nav = ($current_page === $key || strpos($_SERVER['PHP_SELF'], $item['url']) !== false);
            ?>
            <a class="nav-link forge-nav-link <?= $is_active_nav ? 'active' : '' ?>" href="<?= $item['url'] ?>">
                <i class="bi <?= $item['icon'] ?> me-1"></i><?= $item['label'] ?>
            </a>
            <?php endforeach; ?>
            <a class="nav-link forge-nav-link" href="/spence/" style="opacity: 0.4; font-size: 0.75rem;">
                <i class="bi bi-box-arrow-up-right me-1"></i>SPENCE
            </a>
        </div>
    </div>
</nav>
