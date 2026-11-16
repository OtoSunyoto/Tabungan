<?php
require __DIR__ . '/config.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    unset($_SESSION['tab_uid'], $_SESSION['tab_user']);
    session_regenerate_id(true);
    flash('Kamu sudah keluar.');
    redirect('login.php');
}
redirect('index.php');
