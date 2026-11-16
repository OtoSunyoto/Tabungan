<?php
// Dipanggil setelah require 'config.php'. Set $judul sebelum include.
$judul = $judul ?? 'Tabungan Target';
$hideNav = $hideNav ?? false;
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($judul) ?> - Tabungan Muhammad Hafiz S.</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="style.css">
</head>
<body>
<header class="topbar">
    <div class="wrap topbar-in">
        <a class="brand" href="index.php"><span class="brand-icon">💰</span> Tabungan Target</a>
        <?php if (!$hideNav): ?>
        <nav>
            <a href="index.php">Dashboard</a>
            <a href="target_form.php">+ Target Baru</a>
            <a class="btn btn-sm btn-light" href="export.php">⬇ Export Excel</a>
            <form class="inline" method="post" action="logout.php">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline" type="submit" title="Keluar dari akun">👤 <?= e(current_user()) ?> &middot; Keluar</button>
            </form>
        </nav>
        <?php endif; ?>
    </div>
</header>
<main class="wrap">
<?php if ($flash): ?>
    <div class="alert <?= $flash['type'] === 'err' ? 'alert-err' : 'alert-ok' ?>"><?= e($flash['msg']) ?></div>
<?php endif; ?>
