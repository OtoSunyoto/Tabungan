<?php
require __DIR__ . '/config.php';
require_once __DIR__ . '/charts.php';

// Hapus target (beserta seluruh transaksinya)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'hapus_target') {
    csrf_check();
    $st = $pdo->prepare('DELETE FROM target WHERE id = ?');
    $st->execute([(int)($_POST['id'] ?? 0)]);
    flash('Target berhasil dihapus.');
    redirect('index.php');
}

$targets = get_targets($pdo);

$totalTarget = 0;
$totalTerkumpul = 0;
$totalSaldo = 0;
$totalCash = 0;
$tercapai = 0;
foreach ($targets as $t) {
    $totalTarget += (int)$t['target_nominal'];
    $totalTerkumpul += (int)$t['terkumpul'];
    $totalSaldo += (int)$t['di_saldo'];
    $totalCash += (int)$t['di_cash'];
    if ((int)$t['target_nominal'] > 0 && (int)$t['terkumpul'] >= (int)$t['target_nominal']) {
        $tercapai++;
    }
}
$pctTotal = persen($totalTerkumpul, $totalTarget);
$txMap = get_transaksi_per_target($pdo);

// Data grafik: 6 bulan terakhir
$namaBulan = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
$awalBulanIni = new DateTimeImmutable('first day of this month');
$bulanKey = [];
$bulanLabel = [];
for ($i = 5; $i >= 0; $i--) {
    $dt = $awalBulanIni->modify("-$i months");
    $bulanKey[] = $dt->format('Y-m');
    $bulanLabel[] = $namaBulan[(int)$dt->format('n') - 1] . ($dt->format('Y') !== date('Y') ? " '" . $dt->format('y') : '');
}
$sumSetor = array_fill_keys($bulanKey, 0);
$sumTarik = array_fill_keys($bulanKey, 0);
$st = $pdo->prepare('SELECT tanggal, jenis, nominal FROM transaksi WHERE tanggal >= ?');
$st->execute([$awalBulanIni->modify('-5 months')->format('Y-m-d')]);
foreach ($st->fetchAll() as $x) {
    $k = substr($x['tanggal'], 0, 7);
    if (!isset($sumSetor[$k])) continue;
    if ($x['jenis'] === 'setor') $sumSetor[$k] += (int)$x['nominal'];
    else $sumTarik[$k] += (int)$x['nominal'];
}

$judul = 'Dashboard';
require __DIR__ . '/header.php';
?>
<h1>Halo, Hafiz 👋</h1>
<p class="sub">Pantau progres tabungan untuk setiap barang yang ingin kamu beli.</p>

<div class="card hero">
    <div class="label">Total tabungan terkumpul</div>
    <div class="big"><?= rupiah($totalTerkumpul) ?></div>
    <div class="bar"><span style="width: <?= number_format($pctTotal, 2, '.', '') ?>%"></span></div>
    <div class="row">
        <span><?= number_format($pctTotal, 0) ?>% dari total target</span>
        <span><?= rupiah($totalTarget) ?></span>
    </div>
    <div class="hero-split">
        <div class="hero-chip"><span>💳 Saldo</span><strong><?= rupiah($totalSaldo) ?></strong></div>
        <div class="hero-chip"><span>💵 Cash</span><strong><?= rupiah($totalCash) ?></strong></div>
    </div>
</div>

<div class="grid grid-stats">
    <div class="card stat"><div class="ico">🎯</div><div class="label">Jumlah Target</div><div class="value"><?= count($targets) ?></div></div>
    <div class="card stat"><div class="ico">🏆</div><div class="label">Target Tercapai</div><div class="value"><?= $tercapai ?> / <?= count($targets) ?></div></div>
    <div class="card stat"><div class="ico">⏳</div><div class="label">Masih Kurang</div><div class="value"><?= rupiah(max(0, $totalTarget - $totalTerkumpul)) ?></div></div>
</div>

<div class="card" style="margin-bottom:22px">
    <div class="row" style="align-items:center;flex-wrap:wrap">
        <h2 style="margin:0">Aktivitas 6 bulan terakhir</h2>
        <span class="legend"><i class="lg lg-in"></i> Setor <i class="lg lg-out"></i> Tarik</span>
    </div>
    <?= chart_bars($bulanLabel, array_values($sumSetor), array_values($sumTarik)) ?>
    <div class="small muted">Bulan ini: setor <strong class="in"><?= rupiah(end($sumSetor)) ?></strong> &middot; tarik <strong class="out"><?= rupiah(end($sumTarik)) ?></strong></div>
</div>

<h2>Target Saya</h2>
<?php if (!$targets): ?>
    <div class="card empty">
        <div class="em-ico">🌱</div>
        <p>Belum ada target. Yuk buat target pertamamu!</p>
        <a class="btn" href="target_form.php">+ Buat Target</a>
    </div>
<?php else: ?>
<div class="grid grid-cards">
    <?php foreach ($targets as $t):
        $target = (int)$t['target_nominal'];
        $terkumpul = (int)$t['terkumpul'];
        $sisa = max(0, $target - $terkumpul);
        $pct = persen($terkumpul, $target);
        $selesai = $target > 0 && $terkumpul >= $target;

        [$teksEst, $kelasEst] = estimasi_teks(estimasi_selesai($txMap[(int)$t['id']] ?? [], $terkumpul, $target), $t['tanggal_target']);

        $info = '';
        $late = false;
        if ($t['tanggal_target'] && !$selesai) {
            $hariSisa = (int)floor((strtotime($t['tanggal_target']) - strtotime(date('Y-m-d'))) / 86400);
            if ($hariSisa < 0) {
                $late = true;
                $info = 'Lewat ' . abs($hariSisa) . ' hari dari tanggal target';
            } else {
                $bulan = max(1, (int)ceil($hariSisa / 30));
                $info = 'Sisa ' . $hariSisa . ' hari &middot; disarankan nabung ' . e(rupiah((int)ceil($sisa / $bulan))) . '/bulan';
            }
        }
    ?>
    <div class="card hover">
        <div class="tcard-head">
            <div class="ring <?= $selesai ? 'done' : '' ?>" style="--p: <?= number_format($pct, 1, '.', '') ?>"><span><?= number_format($pct, 0) ?>%</span></div>
            <div>
                <div class="nm"><?= e($t['nama']) ?></div>
                <?php if ($selesai): ?>
                    <span class="badge done">Tercapai 🎉</span>
                <?php elseif ($late): ?>
                    <span class="badge late">Terlambat</span>
                <?php else: ?>
                    <span class="badge">Sedang menabung</span>
                <?php endif; ?>
            </div>
        </div>
        <div class="row"><span class="muted">Terkumpul</span><strong><?= rupiah($terkumpul) ?></strong></div>
        <div class="row"><span class="muted">Target</span><span><?= rupiah($target) ?></span></div>
        <div class="row"><span class="muted">Kurang</span><span><?= rupiah($sisa) ?></span></div>
        <div class="row"><span class="muted">💳 Saldo / 💵 Cash</span><span><?= e(fmt_singkat($t['di_saldo'])) ?> / <?= e(fmt_singkat($t['di_cash'])) ?></span></div>
        <div class="row"><span class="muted">Tanggal target</span><span><?= tgl($t['tanggal_target']) ?></span></div>
        <?php if ($info): ?><div class="small muted" style="margin-top:8px"><?= $info ?></div><?php endif; ?>
        <?php if ($teksEst): ?><div class="est <?= e($kelasEst) ?>">📈 <?= e($teksEst) ?></div><?php endif; ?>

        <div class="actions">
            <a class="btn btn-sm" href="target_detail.php?id=<?= (int)$t['id'] ?>">Catat / Detail</a>
            <a class="btn btn-sm btn-outline" href="target_form.php?id=<?= (int)$t['id'] ?>">Edit</a>
            <form class="inline" method="post" data-confirm="Hapus target ini beserta semua transaksinya?">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="hapus_target">
                <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                <button class="btn btn-sm btn-danger" type="submit">Hapus</button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
