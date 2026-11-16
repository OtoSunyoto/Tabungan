<?php
define('PUBLIC_PAGE', true);
require __DIR__ . '/config.php';

if (!empty($_SESSION['tab_uid'])) {
    redirect('index.php');
}

// Buat tabel users otomatis jika belum ada (sama dengan isi update.sql)
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS users (
        id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
        username      VARCHAR(50)  NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_username (username)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Throwable $e) {
    // diabaikan; jika tabel benar-benar tidak ada, query di bawah akan menampilkan error
}

$setup = ((int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn()) === 0;
$errors = [];
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($setup) {
        $konfirmasi = (string)($_POST['konfirmasi'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_.]{3,30}$/', $username)) {
            $errors[] = 'Username 3-30 karakter: huruf, angka, titik, atau garis bawah.';
        }
        if (strlen($password) < 6) $errors[] = 'Password minimal 6 karakter.';
        if ($password !== $konfirmasi) $errors[] = 'Konfirmasi password tidak sama.';

        if (!$errors) {
            $st = $pdo->prepare('INSERT INTO users (username, password_hash) VALUES (?, ?)');
            $st->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
            login_user((int)$pdo->lastInsertId(), $username);
            flash('Akun dibuat. Selamat datang, ' . $username . '!');
            redirect('index.php');
        }
    } else {
        $st = $pdo->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
        $st->execute([$username]);
        $u = $st->fetch();
        if ($u && password_verify($password, $u['password_hash'])) {
            login_user((int)$u['id'], $u['username']);
            redirect('index.php');
        }
        usleep(600000); // perlambat tebak-tebakan password
        $errors[] = 'Username atau password salah.';
    }
}

$judul = $setup ? 'Buat Akun' : 'Masuk';
$hideNav = true;
require __DIR__ . '/header.php';
?>
<div class="auth">
    <div class="card">
        <div class="auth-ico">💰</div>
        <h1><?= $setup ? 'Buat akun pertamamu' : 'Selamat datang kembali' ?></h1>
        <p class="sub">
            <?= $setup
                ? 'Belum ada akun. Buat username dan password untuk mengamankan tabunganmu.'
                : 'Masuk untuk melihat tabungan Muhammad Hafiz S.' ?>
        </p>

        <?php foreach ($errors as $er): ?>
            <div class="alert alert-err"><?= e($er) ?></div>
        <?php endforeach; ?>

        <form method="post" autocomplete="on">
            <?= csrf_field() ?>
            <label for="username">Username</label>
            <input type="text" id="username" name="username" required autofocus autocomplete="username" value="<?= e($username) ?>">

            <label for="password">Password</label>
            <input type="password" id="password" name="password" required autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>">

            <?php if ($setup): ?>
                <label for="konfirmasi">Ulangi password</label>
                <input type="password" id="konfirmasi" name="konfirmasi" required autocomplete="new-password">
            <?php endif; ?>

            <div class="actions"><button class="btn btn-block" type="submit"><?= $setup ? 'Buat Akun &amp; Masuk' : 'Masuk' ?></button></div>
        </form>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
