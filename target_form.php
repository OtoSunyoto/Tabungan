<?php
require __DIR__ . '/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
$data = ['nama' => '', 'target_nominal' => '', 'tanggal_target' => '', 'catatan' => ''];
$errors = [];

if ($id > 0) {
    $st = $pdo->prepare('SELECT * FROM target WHERE id = ?');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        flash('Target tidak ditemukan.', 'err');
        redirect('index.php');
    }
    $data = $row;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $nama = trim((string)($_POST['nama'] ?? ''));
    $nominal = parse_nominal($_POST['target_nominal'] ?? '');
    $tanggal = trim((string)($_POST['tanggal_target'] ?? ''));
    $catatan = trim((string)($_POST['catatan'] ?? ''));

    if ($nama === '' || mb_strlen($nama) > 120) $errors[] = 'Nama target wajib diisi (maksimal 120 karakter).';
    if ($nominal <= 0) $errors[] = 'Nominal target harus lebih dari 0.';
    if ($tanggal !== '' && !valid_date($tanggal)) $errors[] = 'Format tanggal target tidak valid.';
    if (mb_strlen($catatan) > 255) $errors[] = 'Catatan maksimal 255 karakter.';

    $data = ['nama' => $nama, 'target_nominal' => $nominal, 'tanggal_target' => $tanggal, 'catatan' => $catatan];

    if (!$errors) {
        $tglDb = $tanggal !== '' ? $tanggal : null;
        $catDb = $catatan !== '' ? $catatan : null;
        if ($id > 0) {
            $st = $pdo->prepare('UPDATE target SET nama=?, target_nominal=?, tanggal_target=?, catatan=? WHERE id=?');
            $st->execute([$nama, $nominal, $tglDb, $catDb, $id]);
            flash('Target berhasil diperbarui.');
            redirect('target_detail.php?id=' . $id);
        }
        $st = $pdo->prepare('INSERT INTO target (nama, target_nominal, tanggal_target, catatan) VALUES (?,?,?,?)');
        $st->execute([$nama, $nominal, $tglDb, $catDb]);
        flash('Target baru berhasil dibuat. Sekarang kamu bisa mulai mencatat setoran.');
        redirect('target_detail.php?id=' . (int)$pdo->lastInsertId());
    }
}

$judul = $id > 0 ? 'Edit Target' : 'Target Baru';
require __DIR__ . '/header.php';
?>
<h1><?= e($judul) ?></h1>
<p class="sub">Isi barang/tujuan yang ingin kamu beli dan berapa dana yang dibutuhkan.</p>

<div class="card" style="max-width:560px">
    <?php foreach ($errors as $er): ?>
        <div class="alert alert-err"><?= e($er) ?></div>
    <?php endforeach; ?>

    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$id ?>">

        <label for="nama">Nama target / barang</label>
        <input type="text" id="nama" name="nama" maxlength="120" required placeholder="mis. Laptop, Motor, Liburan" value="<?= e($data['nama']) ?>">

        <label for="target_nominal">Nominal target (Rp)</label>
        <input type="text" class="rupiah" id="target_nominal" name="target_nominal" inputmode="numeric" required placeholder="8.000.000" value="<?= e($data['target_nominal']) ?>">

        <label for="tanggal_target">Tanggal target tercapai (opsional)</label>
        <input type="date" id="tanggal_target" name="tanggal_target" value="<?= e($data['tanggal_target']) ?>">

        <label for="catatan">Catatan (opsional)</label>
        <textarea id="catatan" name="catatan" rows="2" maxlength="255"><?= e($data['catatan']) ?></textarea>

        <div class="actions">
            <button class="btn" type="submit">Simpan</button>
            <a class="btn btn-outline" href="<?= $id > 0 ? 'target_detail.php?id=' . (int)$id : 'index.php' ?>">Batal</a>
        </div>
    </form>
</div>

<?php require __DIR__ . '/footer.php'; ?>
