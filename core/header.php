<?php
/**
 * FORGE Global Header
 */
$current_page = basename(dirname($_SERVER['PHP_SELF']));
if ($current_page === 'forge') $current_page = 'home';

$nav_items = [
    'workout'   => ['label' => 'WORKOUTS',  'url' => '/forge/workout/',   'icon' => 'bi-lightning-charge-fill'],
    'progress'  => ['label' => 'PROGRESS',  'url' => '/forge/progress/',  'icon' => 'bi-graph-up-arrow'],
    'history'   => ['label' => 'HISTORY',   'url' => '/forge/history/',   'icon' => 'bi-clock-history'],
    'exercises' => ['label' => 'EXERCISES', 'url' => '/forge/exercises/', 'icon' => 'bi-list-ul'],
    'profile'   => ['label' => 'PROFILE',   'url' => '/forge/profile/',   'icon' => 'bi-person-fill'],
];
?>
<nav class="navbar navbar-expand-lg navbar-dark sticky-top" style="background:#0a0a0a !important; border-bottom: 2px solid #222;">
    <div class="container-fluid px-3">
        <a class="navbar-brand" href="/forge/" style="font-weight:900; letter-spacing:-2px; font-size:1.6rem; line-height:1;">
            <span style="color:#FF5722;">FORGE</span>
        </a>
        <button class="navbar-toggler border-0" type="button" data-bs-toggle="collapse" data-bs-target="#forgeNav" style="color:#888;">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="forgeNav">
            <ul class="navbar-nav ms-auto gap-1">
                <?php foreach ($nav_items as $key => $item): ?>
                <li class="nav-item">
                    <a class="nav-link forge-nav-link <?= ($current_page === $key) ? 'active' : '' ?>"
                       href="<?= $item['url'] ?>">
                        <i class="bi <?= $item['icon'] ?> me-1"></i><?= $item['label'] ?>
                    </a>
                </li>
                <?php endforeach; ?>
                <li class="nav-item">
                    <a class="nav-link forge-nav-link" href="/spence/" style="opacity:0.4; font-size:0.75rem;">
                        <i class="bi bi-box-arrow-up-right me-1"></i>SPENCE
                    </a>
                </li>
            </ul>
        </div>
    </div>
</nav>
