<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Nama sesi khusus agar tidak bentrok dengan project PHP lain di localhost
    session_name('TABUNGAN_SESS');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

// ====== PENGATURAN DATABASE (sesuaikan jika perlu) ======
const DB_HOST = 'localhost';
const DB_NAME = 'tabungan_db';
const DB_USER = 'root';
const DB_PASS = '';   // default XAMPP: kosong
// =========================================================

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('Koneksi database gagal. Pastikan MySQL di XAMPP aktif dan database "tabungan_db" sudah di-import. '
        . '<br><small>' . htmlspecialchars($e->getMessage()) . '</small>');
}

/* ---------- Helper umum ---------- */

function e($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function rupiah($n): string
{
    return 'Rp ' . number_format((float)$n, 0, ',', '.');
}

function tgl($date): string
{
    if (!$date) return '-';
    $t = strtotime((string)$date);
    return $t ? date('d/m/Y', $t) : '-';
}

function flash(string $msg, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function csrf_field(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">';
}

function csrf_check(): void
{
    $ok = isset($_SESSION['csrf'], $_POST['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
    if (!$ok) {
        http_response_code(400);
        die('Token keamanan tidak valid. Silakan kembali dan muat ulang halaman.');
    }
}

/** "1.500.000" / "Rp 1500000" -> 1500000 (rupiah bulat) */
function parse_nominal($s): int
{
    return (int)preg_replace('/[^0-9]/', '', (string)$s);
}

function valid_date(?string $d): bool
{
    if (!$d) return false;
    $dt = DateTime::createFromFormat('Y-m-d', $d);
    return $dt && $dt->format('Y-m-d') === $d;
}

function persen($terkumpul, $target): float
{
    if ((float)$target <= 0) return 0.0;
    return max(0.0, min(100.0, ((float)$terkumpul / (float)$target) * 100));
}

/* ---------- Query bersama ---------- */

/**
 * Ambil target beserta total terkumpul (setor - tarik).
 * Jika $id diberikan, hanya satu target.
 */
function get_targets(PDO $pdo, ?int $id = null): array
{
    // Nilai bertanda: setor = +, tarik = -. Dipecah per metode (saldo / cash).
    $amt = "(CASE WHEN x.jenis='setor' THEN CAST(x.nominal AS SIGNED) ELSE -CAST(x.nominal AS SIGNED) END)";
    $sql = "SELECT t.*,
                   COALESCE(SUM($amt), 0) AS terkumpul,
                   COALESCE(SUM(CASE WHEN x.metode='saldo' THEN $amt ELSE 0 END), 0) AS di_saldo,
                   COALESCE(SUM(CASE WHEN x.metode='cash'  THEN $amt ELSE 0 END), 0) AS di_cash,
                   (SELECT COALESCE(SUM(CASE WHEN p.dari='cash' THEN CAST(p.nominal AS SIGNED) ELSE -CAST(p.nominal AS SIGNED) END), 0)
                      FROM pindah p WHERE p.target_id = t.id) AS geser
            FROM target t
            LEFT JOIN transaksi x ON x.target_id = t.id";
    $params = [];
    if ($id !== null) {
        $sql .= ' WHERE t.id = ?';
        $params[] = $id;
    }
    $sql .= ' GROUP BY t.id ORDER BY t.created_at DESC, t.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    // Terapkan pemindahan dana: geser > 0 berarti dana berpindah dari cash ke saldo (total tidak berubah)
    foreach ($rows as &$r) {
        $g = (int)$r['geser'];
        $r['di_saldo'] = (int)$r['di_saldo'] + $g;
        $r['di_cash']  = (int)$r['di_cash'] - $g;
    }
    unset($r);
    return $rows;
}

/* ---------- Metode penyimpanan: Saldo (rekening/e-wallet) atau Cash (tunai) ---------- */

const METODE = [
    'saldo' => '💳 Saldo',
    'cash'  => '💵 Cash',
];

function metode_valid($m): string
{
    return array_key_exists((string)$m, METODE) ? (string)$m : 'saldo';
}

/** Tambah kolom "metode" di tabel transaksi bila belum ada (untuk database lama). Berjalan otomatis. */
function migrate_db(PDO $pdo): void
{
    if (!empty($_SESSION['db_migrated'])) return;
    try {
        $ada = $pdo->query("SHOW COLUMNS FROM transaksi LIKE 'metode'")->fetch();
        if (!$ada) {
            $pdo->exec("ALTER TABLE transaksi ADD COLUMN metode ENUM('saldo','cash') NOT NULL DEFAULT 'saldo' AFTER jenis");
        }
        // Tabel pemindahan dana saldo <-> cash
        $pdo->exec("CREATE TABLE IF NOT EXISTS pindah (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            target_id  INT UNSIGNED NOT NULL,
            tanggal    DATE NOT NULL,
            dari       ENUM('saldo','cash') NOT NULL,
            nominal    BIGINT UNSIGNED NOT NULL,
            keterangan VARCHAR(255) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_pindah_target (target_id, tanggal),
            CONSTRAINT fk_pindah_target FOREIGN KEY (target_id) REFERENCES target (id) ON DELETE CASCADE ON UPDATE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $_SESSION['db_migrated'] = true;
    } catch (Throwable $e) {
        // diabaikan; jika kolom benar-benar tidak ada, query berikutnya akan menampilkan error yang jelas
    }
}
migrate_db($pdo);

/** Semua transaksi dikelompokkan per target_id: [target_id => [ [tanggal,jenis,nominal], ... ]] */
function get_transaksi_per_target(PDO $pdo): array
{
    $map = [];
    $rows = $pdo->query('SELECT target_id, tanggal, jenis, nominal FROM transaksi ORDER BY tanggal ASC, id ASC')->fetchAll();
    foreach ($rows as $r) {
        $map[(int)$r['target_id']][] = $r;
    }
    return $map;
}

/* ---------- Estimasi tanggal tercapai ---------- */

/**
 * Perkiraan kapan target tercapai berdasarkan laju menabung bersih
 * (setor - tarik) pada maksimal 90 hari terakhir (minimal jendela 14 hari).
 * Mengembalikan null jika target sudah tercapai.
 */
function estimasi_selesai(array $txs, int $terkumpul, int $target): ?array
{
    $sisa = $target - $terkumpul;
    if ($sisa <= 0) return null;
    if (!$txs) return ['ok' => false, 'alasan' => 'belum'];

    $today = new DateTimeImmutable('today');
    $first = min(array_column($txs, 'tanggal'));
    $umur = max(0, (int)(new DateTimeImmutable($first))->diff($today)->format('%r%a')) + 1;
    $span = max(14, min(90, $umur));
    $start = $today->modify('-' . ($span - 1) . ' days')->format('Y-m-d');
    $end = $today->format('Y-m-d');

    $net = 0;
    foreach ($txs as $x) {
        if ($x['tanggal'] >= $start && $x['tanggal'] <= $end) {
            $net += $x['jenis'] === 'setor' ? (int)$x['nominal'] : -(int)$x['nominal'];
        }
    }
    $perHari = $net / $span;
    if ($perHari <= 0) return ['ok' => false, 'alasan' => 'stagnan'];

    $hari = (int)ceil($sisa / $perHari);
    return [
        'ok'        => true,
        'per_bulan' => (int)round($perHari * 30),
        'hari'      => $hari,
        'tanggal'   => $hari > 3650 ? null : $today->modify('+' . $hari . ' days')->format('Y-m-d'),
    ];
}

/** Ubah hasil estimasi jadi [teks, kelas] ; kelas: good | late | neutral */
function estimasi_teks(?array $est, ?string $tanggalTarget): array
{
    if ($est === null) return ['', 'neutral'];
    if (!$est['ok']) {
        return $est['alasan'] === 'belum'
            ? ['Belum ada setoran. Mulai menabung untuk melihat estimasi tanggal tercapai.', 'neutral']
            : ['Belum bisa diperkirakan: tidak ada tambahan tabungan bersih dalam 90 hari terakhir.', 'late'];
    }
    if ($est['tanggal'] === null) {
        return ['Dengan laju sekarang (±' . rupiah($est['per_bulan']) . '/bulan) butuh lebih dari 10 tahun.', 'late'];
    }
    $teks = 'Estimasi tercapai ' . tgl($est['tanggal']) . ' (laju ±' . rupiah($est['per_bulan']) . '/bulan)';
    $kelas = 'neutral';
    if ($tanggalTarget) {
        $selisih = (int)round((strtotime($tanggalTarget) - strtotime($est['tanggal'])) / 86400);
        if ($selisih >= 0) {
            $teks .= $selisih === 0 ? ', tepat waktu.' : ', ' . $selisih . ' hari lebih cepat dari target.';
            $kelas = 'good';
        } else {
            $teks .= ', ' . abs($selisih) . ' hari lebih lambat dari target.';
            $kelas = 'late';
        }
    }
    return [$teks, $kelas];
}

/* ---------- Login ---------- */

function current_user(): ?string
{
    return $_SESSION['tab_user'] ?? null;
}

function login_user(int $id, string $username): void
{
    session_regenerate_id(true);
    $_SESSION['tab_uid'] = $id;
    $_SESSION['tab_user'] = $username;
}

function require_login(): void
{
    if (empty($_SESSION['tab_uid'])) {
        redirect('login.php');
    }
}

// Semua halaman wajib login, kecuali yang mendefinisikan PUBLIC_PAGE (login.php)
if (!defined('PUBLIC_PAGE')) {
    require_login();
}
