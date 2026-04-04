<?php
/**
 * FORGE Page Shell — Head
 * Variables to set before including:
 *   $page_title   (string)  — e.g. "Workout"
 *   $page_context (string)  — e.g. "workout"
 *   $extra_head   (string?) — optional extra <head> content (e.g. Chart.js CDN)
 *   $extra_styles (string?) — optional <style> block for page-specific CSS
 */
?>
<!DOCTYPE html>
<html lang="en" data-context="<?= htmlspecialchars($page_context) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>FORGE | <?= htmlspecialchars($page_title) ?></title>
    <link rel="icon" href="/forge/core/favicon.svg" type="image/svg+xml">
    <link rel="manifest" href="/forge/core/site.webmanifest">
    <meta name="theme-color" content="#FF5722">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">
    <link rel="stylesheet" href="/forge/core/forge.css?v=4">
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <?= $extra_head ?? '' ?>
    <?= $extra_styles ?? '' ?>
</head>
<?php
// Detect active workout for site-wide bar + body class
$_ab_active = false;
if (isset($db)) {
    $s = $db->query("SELECT id FROM workouts WHERE finished_at IS NULL LIMIT 1");
    $_ab_active = (bool)$s->fetchColumn();
}
?>
<body class="<?= $_ab_active ? 'workout-bar-active' : '' ?>">
<?php if (empty($skip_header)) include __DIR__ . '/header.php'; ?>
<?php include __DIR__ . '/active_workout_bar.php'; ?>
