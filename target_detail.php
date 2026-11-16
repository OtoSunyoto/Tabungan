<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/charts.php';

$id = (int)($_GET['id'] ?? $_POST['target_id'] ?? 0);
$rows = $id > 0 ? get_targets($pdo, $id) : [];
if (!$rows) {
    flash('Target tidak ditemukan.', 'err');
    redirect('index.php');
}
$t = $rows[0];
$errors = [];
$errorsP = [];
$form = ['tanggal' => date('Y-m-d'), 'jenis' => 'setor', 'metode' => 'saldo', 'nominal' => '', 'keterangan' => ''];
$formP = ['tanggal' => date('Y-m-d'), 'dari' => 'saldo', 'nominal' => '', 'keterangan' => ''];

/** Apakah perubahan $d (['saldo'=>±n,'cash'=>±n]) membuat saldo/cash target jadi minus? */
function bikin_minus(array $t, array $d): bool
{
    return (int)$t['di_saldo'] + $d['saldo'] < 0 || (int)$t['di_cash'] + $d['cash'] < 0;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'tambah') {
        $tanggal = trim((string)($_POST['tanggal'] ?? ''));
        $jenis = ($_POST['jenis'] ?? '') === 'tarik' ? 'tarik' : 'setor';
        $metode = metode_valid($_POST['metode'] ?? 'saldo');
        $nominal = parse_nominal($_POST['nominal'] ?? '');
        $ket = trim((string)($_POST['keterangan'] ?? ''));

        if (!valid_date($tanggal)) $errors[] = 'Tanggal tidak valid.';
        if ($nominal <= 0) $errors[] = 'Nominal harus lebih dari 0.';
        if ($jenis === 'tarik') {
            $tersedia = (int)($metode === 'cash' ? $t['di_cash'] : $t['di_saldo']);
            if ($nominal > $tersedia) {
                $errors[] = 'Penarikan melebihi ' . ($metode === 'cash' ? 'cash' : 'saldo') . ' yang tersedia di target ini (' . rupiah($tersedia) . ').';
            }
        }
        if (mb_strlen($ket) > 255) $errors[] = 'Keterangan maksimal 255 karakter.';

        $form = ['tanggal' => $tanggal, 'jenis' => $jenis, 'metode' => $metode, 'nominal' => $nominal ?: '', 'keterangan' => $ket];

        if (!$errors) {
            $st = $pdo->prepare('INSERT INTO transaksi (target_id, tanggal, jenis, metode, nominal, keterangan) VALUES (?,?,?,?,?,?)');
            $st->execute([$id, $tanggal, $jenis, $metode, $nominal, $ket !== '' ? $ket : null]);
            flash($jenis === 'setor' ? 'Setoran berhasil dicatat.' : 'Penarikan berhasil dicatat.');
            redirect('target_detail.php?id=' . $id);
        }
    }

    if ($action === 'pindah') {
        $tanggal = trim((string)($_POST['tanggal'] ?? ''));
        $dari = metode_valid($_POST['dari'] ?? 'saldo');
        $ke = $dari === 'saldo' ? 'cash' : 'saldo';
        $nominal = parse_nominal($_POST['nominal'] ?? '');
        $ket = trim((string)($_POST['keterangan'] ?? ''));

        if (!valid_date($tanggal)) $errorsP[] = 'Tanggal tidak valid.';
        if ($nominal <= 0) $errorsP[] = 'Nominal harus lebih dari 0.';
        $tersedia = (int)($dari === 'cash' ? $t['di_cash'] : $t['di_saldo']);
        if ($nominal > $tersedia) {
            $errorsP[] = 'Dana di ' . ($dari === 'cash' ? 'cash' : 'saldo') . ' tidak cukup (tersedia ' . rupiah($tersedia) . ').';
        }
        if (mb_strlen($ket) > 255) $errorsP[] = 'Keterangan maksimal 255 karakter.';

        $formP = ['tanggal' => $tanggal, 'dari' => $dari, 'nominal' => $nominal ?: '', 'keterangan' => $ket];

        if (!$errorsP) {
            $st = $pdo->prepare('INSERT INTO pindah (target_id, tanggal, dari, nominal, keterangan) VALUES (?,?,?,?,?)');
            $st->execute([$id, $tanggal, $dari, $nominal, $ket !== '' ? $ket : null]);
            flash('Dana ' . rupiah($nominal) . ' dipindahkan dari ' . ($dari === 'saldo' ? 'Saldo ke Cash' : 'Cash ke Saldo') . '.');
            redirect('target_detail.php?id=' . $id);
        }
    }

    if ($action === 'hapus_tx') {
        $txId = (int)($_POST['tx_id'] ?? 0);
        $st = $pdo->prepare('SELECT jenis, metode, nominal FROM transaksi WHERE id = ? AND target_id = ?');
        $st->execute([$txId, $id]);
        $row = $st->fetch();
        if ($row) {
            $n = (int)$row['nominal'];
            $d = ['saldo' => 0, 'cash' => 0];
            $d[metode_valid($row['metode'])] += $row['jenis'] === 'setor' ? -$n : $n;
            if (bikin_minus($t, $d)) {
                flash('Tidak bisa dihapus: saldo/cash target ini akan menjadi minus. Hapus dulu penarikan atau pemindahan yang bergantung padanya.', 'err');
            } else {
                $pdo->prepare('DELETE FROM transaksi WHERE id = ? AND target_id = ?')->execute([$txId, $id]);
                flash('Transaksi dihapus.');
            }
        }
        redirect('target_detail.php?id=' . $id);
    }

    if ($action === 'hapus_pindah') {
        $pid = (int)($_POST['tx_id'] ?? 0);
        $st = $pdo->prepare('SELECT dari, nominal FROM pindah WHERE id = ? AND target_id = ?');
        $st->execute([$pid, $id]);
        $row = $st->fetch();
        if ($row) {
            $n = (int)$row['nominal'];
            // Menghapus pemindahan = mengembalikan dana ke asalnya
            $d = $row['dari'] === 'saldo' ? ['saldo' => $n, 'cash' => -$n] : ['saldo' => -$n, 'cash' => $n];
            if (bikin_minus($t, $d)) {
                flash('Tidak bisa dihapus: dana hasil pemindahan sudah terpakai sehingga akan menjadi minus.', 'err');
            } else {
                $pdo->prepare('DELETE FROM pindah WHERE id = ? AND target_id = ?')->execute([$pid, $id]);
                flash('Pemindahan dihapus.');
            }
        }
        redirect('target_detail.php?id=' . $id);
    }
}

$st = $pdo->prepare('SELECT * FROM transaksi WHERE target_id = ? ORDER BY tanggal DESC, id DESC');
$st->execute([$id]);
$transaksi = $st->fetchAll();

$st = $pdo->prepare('SELECT * FROM pindah WHERE target_id = ? ORDER BY tanggal DESC, id DESC');
$st->execute([$id]);
$pindahRows = $st->fetchAll();

// Riwayat gabungan (transaksi + pemindahan), terbaru di atas
$riwayat = [];
foreach ($transaksi as $x) $riwayat[] = ['tipe' => 'tx', 'd' => $x];
foreach ($pindahRows as $x) $riwayat[] = ['tipe' => 'pindah', 'd' => $x];
usort($riwayat, fn($a, $b) => [$b['d']['tanggal'], (string)$b['d']['created_at'], (int)$b['d']['id']]
                          <=> [$a['d']['tanggal'], (string)$a['d']['created_at'], (int)$a['d']['id']]);

$target = (int)$t['target_nominal'];
$terkumpul = (int)$t['terkumpul'];
$sisa = max(0, $target - $terkumpul);
$pct = persen($terkumpul, $target);
$selesai = $target > 0 && $terkumpul >= $target;
[$teksEst, $kelasEst] = estimasi_teks(estimasi_selesai($transaksi, $terkumpul, $target), $t['tanggal_target']);

$judul = $t['nama'];
require __DIR__ . '/header.php';
?>
<h1><?= e($t['nama']) ?></h1>
<p class="sub">
    <?= $t['catatan'] ? e($t['catatan']) . ' &middot; ' : '' ?>
    Tanggal target: <?= tgl($t['tanggal_target']) ?>
</p>

<div class="card" style="margin-bottom:20px">
    <div class="row">
        <span><strong><?= rupiah($terkumpul) ?></strong> <span class="muted">dari <?= rupiah($target) ?></span></span>
        <strong><?= number_format($pct, 1, ',', '.') ?>%</strong>
    </div>
    <div class="bar <?= $selesai ? 'done' : '' ?>"><span style="width: <?= number_format($pct, 2, '.', '') ?>%"></span></div>
    <div class="row">
        <span class="muted"><?= $selesai ? 'Target tercapai 🎉' : 'Kurang ' . rupiah($sisa) ?></span>
        <span class="muted"><?= count($transaksi) ?> transaksi<?= $pindahRows ? ' &middot; ' . count($pindahRows) . ' pemindahan' : '' ?></span>
    </div>
    <div class="split">
        <div class="split-item"><span class="muted">💳 Di Saldo</span><strong><?= rupiah($t['di_saldo']) ?></strong></div>
        <div class="split-item"><span class="muted">💵 Di Cash</span><strong><?= rupiah($t['di_cash']) ?></strong></div>
    </div>
    <?php if ($teksEst): ?><div class="est <?= e($kelasEst) ?>">📈 <?= e($teksEst) ?></div><?php endif; ?>
    <div class="actions">
        <a class="btn btn-sm btn-outline" href="target_form.php?id=<?= $id ?>">Edit Target</a>
        <a class="btn btn-sm btn-outline" href="export.php?id=<?= $id ?>">⬇ Export Excel (target ini)</a>
        <a class="btn btn-sm btn-outline" href="index.php">← Kembali</a>
    </div>
</div>

<div class="card" style="margin-bottom:20px">
    <h2>Perkembangan Saldo</h2>
    <?= chart_saldo(saldo_points(array_reverse($transaksi)), $target) ?>
</div>

<div class="card" style="margin-bottom:20px">
    <h2>Catat Transaksi</h2>
    <?php foreach ($errors as $er): ?>
        <div class="alert alert-err"><?= e($er) ?></div>
    <?php endforeach; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="tambah">
        <input type="hidden" name="target_id" value="<?= $id ?>">
        <div class="form-grid">
            <div>
                <label for="tanggal">Tanggal</label>
                <input type="date" id="tanggal" name="tanggal" required value="<?= e($form['tanggal']) ?>">
            </div>
            <div>
                <label for="jenis">Jenis</label>
                <select id="jenis" name="jenis">
                    <option value="setor" <?= $form['jenis'] === 'setor' ? 'selected' : '' ?>>Setor (menabung)</option>
                    <option value="tarik" <?= $form['jenis'] === 'tarik' ? 'selected' : '' ?>>Tarik</option>
                </select>
            </div>
            <div>
                <label for="metode">Disimpan di</label>
                <select id="metode" name="metode">
                    <?php foreach (METODE as $k => $lbl): ?>
                        <option value="<?= e($k) ?>" <?= $form['metode'] === $k ? 'selected' : '' ?>><?= e($lbl) ?><?= $k === 'saldo' ? ' (rekening)' : ' (tunai)' ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="nominal">Nominal (Rp)</label>
                <input type="text" class="rupiah" id="nominal" name="nominal" inputmode="numeric" required placeholder="100.000" value="<?= e($form['nominal']) ?>">
            </div>
        </div>
        <label for="keterangan">Keterangan (opsional)</label>
        <input type="text" id="keterangan" name="keterangan" maxlength="255" placeholder="mis. Gaji bulan ini" value="<?= e($form['keterangan']) ?>">
        <div class="actions"><button class="btn" type="submit">Simpan Transaksi</button></div>
    </form>
</div>

<div class="card" style="margin-bottom:20px">
    <h2>⇄ Pindah Dana Saldo / Cash</h2>
    <p class="muted small" style="margin-top:-6px">Misalnya tarik tunai dari rekening, atau menyetor uang tunai ke rekening. Total tabungan tidak berubah.</p>
    <?php foreach ($errorsP as $er): ?>
        <div class="alert alert-err"><?= e($er) ?></div>
    <?php endforeach; ?>
    <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="pindah">
        <input type="hidden" name="target_id" value="<?= $id ?>">
        <div class="form-grid">
            <div>
                <label for="p_tanggal">Tanggal</label>
                <input type="date" id="p_tanggal" name="tanggal" required value="<?= e($formP['tanggal']) ?>">
            </div>
            <div>
                <label for="p_dari">Arah pemindahan</label>
                <select id="p_dari" name="dari">
                    <option value="saldo" <?= $formP['dari'] === 'saldo' ? 'selected' : '' ?>>💳 Saldo → 💵 Cash</option>
                    <option value="cash" <?= $formP['dari'] === 'cash' ? 'selected' : '' ?>>💵 Cash → 💳 Saldo</option>
                </select>
            </div>
            <div>
                <label for="p_nominal">Nominal (Rp)</label>
                <input type="text" class="rupiah" id="p_nominal" name="nominal" inputmode="numeric" required placeholder="100.000" value="<?= e($formP['nominal']) ?>">
            </div>
        </div>
        <label for="p_ket">Keterangan (opsional)</label>
        <input type="text" id="p_ket" name="keterangan" maxlength="255" placeholder="mis. Tarik tunai di ATM" value="<?= e($formP['keterangan']) ?>">
        <div class="actions"><button class="btn" type="submit">Pindahkan Dana</button></div>
    </form>
</div>

<div class="card">
    <h2>Riwayat</h2>
    <?php if (!$riwayat): ?>
        <div class="empty">Belum ada transaksi untuk target ini.</div>
    <?php else: ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Tanggal</th><th>Jenis</th><th>Disimpan di</th><th class="num">Nominal</th><th>Keterangan</th><th></th></tr>
            </thead>
            <tbody>
            <?php foreach ($riwayat as $r): $x = $r['d']; $isP = $r['tipe'] === 'pindah'; ?>
                <tr>
                    <td><?= tgl($x['tanggal']) ?></td>
                    <?php if ($isP): ?>
                        <td>Pindah</td>
                        <td><span class="badge move"><?= $x['dari'] === 'saldo' ? '💳 Saldo → 💵 Cash' : '💵 Cash → 💳 Saldo' ?></span></td>
                        <td class="num mv">⇄ <?= rupiah($x['nominal']) ?></td>
                    <?php else: ?>
                        <td><?= $x['jenis'] === 'setor' ? 'Setor' : 'Tarik' ?></td>
                        <td><span class="badge <?= ($x['metode'] ?? 'saldo') === 'cash' ? 'cash' : '' ?>"><?= e(METODE[metode_valid($x['metode'] ?? 'saldo')]) ?></span></td>
                        <td class="num <?= $x['jenis'] === 'setor' ? 'in' : 'out' ?>">
                            <?= $x['jenis'] === 'setor' ? '+' : '−' ?> <?= rupiah($x['nominal']) ?>
                        </td>
                    <?php endif; ?>
                    <td class="wrap-text"><?= e($x['keterangan'] ?? '') ?></td>
                    <td>
                        <form class="inline" method="post" data-confirm="<?= $isP ? 'Hapus pemindahan ini?' : 'Hapus transaksi ini?' ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="<?= $isP ? 'hapus_pindah' : 'hapus_tx' ?>">
                            <input type="hidden" name="target_id" value="<?= $id ?>">
                            <input type="hidden" name="tx_id" value="<?= (int)$x['id'] ?>">
                            <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/footer.php'; ?>
