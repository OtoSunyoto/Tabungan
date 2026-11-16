<?php
/**
 * Export data tabungan ke Excel (.xlsx) tanpa library eksternal.
 * - export.php           -> semua target
 * - export.php?id=3      -> hanya target dengan id 3
 * Butuh ekstensi PHP "zip" (aktif secara default di XAMPP).
 */
require __DIR__ . '/config.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$targets = get_targets($pdo, $id > 0 ? $id : null);
if ($id > 0 && !$targets) {
    flash('Target tidak ditemukan.', 'err');
    redirect('index.php');
}

$sql = "SELECT x.tanggal, t.nama, x.jenis, x.metode, x.nominal, x.keterangan
        FROM transaksi x JOIN target t ON t.id = x.target_id";
$params = [];
if ($id > 0) {
    $sql .= ' WHERE x.target_id = ?';
    $params[] = $id;
}
$sql .= ' ORDER BY x.tanggal ASC, x.id ASC';
$st = $pdo->prepare($sql);
$st->execute($params);
$transaksi = $st->fetchAll();

$sqlP = "SELECT p.tanggal, t.nama, p.dari, p.nominal, p.keterangan
         FROM pindah p JOIN target t ON t.id = p.target_id";
$paramsP = [];
if ($id > 0) {
    $sqlP .= ' WHERE p.target_id = ?';
    $paramsP[] = $id;
}
$sqlP .= ' ORDER BY p.tanggal ASC, p.id ASC';
$st = $pdo->prepare($sqlP);
$st->execute($paramsP);
$pindahan = $st->fetchAll();

$namaFile = 'tabungan_' . ($id > 0 ? preg_replace('/[^A-Za-z0-9_-]+/', '_', $targets[0]['nama']) . '_' : '') . date('Ymd_His');

/* ---------- Fallback CSV jika ekstensi zip tidak aktif ---------- */
if (!class_exists('ZipArchive')) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $namaFile . '.csv"');
    echo "\xEF\xBB\xBF"; // BOM agar Excel membaca UTF-8
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Tanggal', 'Target', 'Jenis', 'Disimpan di', 'Nominal', 'Keterangan'], ';');
    foreach ($transaksi as $x) {
        fputcsv($out, [tgl($x['tanggal']), $x['nama'], $x['jenis'] === 'setor' ? 'Setor' : 'Tarik', $x['metode'] === 'cash' ? 'Cash' : 'Saldo', $x['nominal'], $x['keterangan']], ';');
    }
    fclose($out);
    exit;
}

/* ---------- Helper pembuat XLSX ---------- */

const ST_DEFAULT = 0;
const ST_HEADER  = 1;
const ST_NUMBER  = 2;
const ST_DATE    = 3;
const ST_PERCENT = 4;
const ST_BOLDNUM = 5;
const ST_BOLD    = 6;

function xml_esc($s): string
{
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}]/u', '', (string)$s) ?? '';
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col_name(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function excel_date(?string $d): ?int
{
    if (!$d) return null;
    $t = strtotime($d . ' 00:00:00 UTC');
    return $t === false ? null : (int)floor($t / 86400) + 25569;
}

/**
 * Sel: nilai biasa (string|int|float|null) atau ['f' => 'B2-C2', 'v' => cachedValue]
 */
function xml_cell(int $c, int $r, $v, int $style = ST_DEFAULT): string
{
    $ref = col_name($c) . $r;
    if (is_array($v)) {
        return '<c r="' . $ref . '" s="' . $style . '"><f>' . xml_esc($v['f']) . '</f><v>' . $v['v'] . '</v></c>';
    }
    if ($v === null || $v === '') {
        return '<c r="' . $ref . '" s="' . $style . '"/>';
    }
    if (is_int($v) || is_float($v)) {
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . $v . '</v></c>';
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xml_esc($v) . '</t></is></c>';
}

/** $rows: array of rows; each row = array of [value, style] */
function sheet_xml(array $rows, array $widths): string
{
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
       . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
       . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
       . '<cols>';
    foreach ($widths as $i => $w) {
        $n = $i + 1;
        $x .= '<col min="' . $n . '" max="' . $n . '" width="' . $w . '" customWidth="1"/>';
    }
    $x .= '</cols><sheetData>';
    foreach ($rows as $ri => $row) {
        $r = $ri + 1;
        $x .= '<row r="' . $r . '">';
        foreach ($row as $ci => $cell) {
            $x .= xml_cell($ci, $r, $cell[0], $cell[1] ?? ST_DEFAULT);
        }
        $x .= '</row>';
    }
    return $x . '</sheetData></worksheet>';
}

/* ---------- Sheet 1: Ringkasan ---------- */

$H = fn(string $t) => [$t, ST_HEADER];
$ringkasan = [[
    $H('Nama Target'), $H('Target (Rp)'), $H('Terkumpul (Rp)'), $H('Sisa (Rp)'),
    $H('Progres'), $H('Tanggal Target'), $H('Status'), $H('Di Saldo (Rp)'), $H('Di Cash (Rp)'),
]];

$sumTarget = 0;
$sumTerkumpul = 0;
$sumSaldo = 0;
$sumCash = 0;
foreach ($targets as $t) {
    $r = count($ringkasan) + 1; // nomor baris Excel
    $tg = (int)$t['target_nominal'];
    $tk = (int)$t['terkumpul'];
    $sumTarget += $tg;
    $sumTerkumpul += $tk;
    $sumSaldo += (int)$t['di_saldo'];
    $sumCash += (int)$t['di_cash'];
    $ringkasan[] = [
        [$t['nama'], ST_DEFAULT],
        [$tg, ST_NUMBER],
        [$tk, ST_NUMBER],
        [['f' => "MAX(B$r-C$r,0)", 'v' => max(0, $tg - $tk)], ST_NUMBER],
        [['f' => "IF(B$r=0,0,C$r/B$r)", 'v' => $tg > 0 ? round($tk / $tg, 6) : 0], ST_PERCENT],
        [excel_date($t['tanggal_target']), ST_DATE],
        [$tg > 0 && $tk >= $tg ? 'Tercapai' : 'Menabung', ST_DEFAULT],
        [(int)$t['di_saldo'], ST_NUMBER],
        [(int)$t['di_cash'], ST_NUMBER],
    ];
}
if (count($ringkasan) > 1) {
    $last = count($ringkasan);
    $r = $last + 1;
    $ringkasan[] = [
        ['TOTAL', ST_BOLD],
        [['f' => "SUM(B2:B$last)", 'v' => $sumTarget], ST_BOLDNUM],
        [['f' => "SUM(C2:C$last)", 'v' => $sumTerkumpul], ST_BOLDNUM],
        [['f' => "SUM(D2:D$last)", 'v' => max(0, $sumTarget - $sumTerkumpul)], ST_BOLDNUM],
        [['f' => "IF(B$r=0,0,C$r/B$r)", 'v' => $sumTarget > 0 ? round($sumTerkumpul / $sumTarget, 6) : 0], ST_PERCENT],
        ['', ST_DEFAULT],
        ['', ST_DEFAULT],
        [['f' => "SUM(H2:H$last)", 'v' => $sumSaldo], ST_BOLDNUM],
        [['f' => "SUM(I2:I$last)", 'v' => $sumCash], ST_BOLDNUM],
    ];
}

/* ---------- Sheet 2: Transaksi ---------- */

$tx = [[$H('Tanggal'), $H('Target'), $H('Jenis'), $H('Nominal (Rp)'), $H('Pengaruh Saldo (Rp)'), $H('Keterangan'), $H('Disimpan di')]];
foreach ($transaksi as $x) {
    $r = count($tx) + 1;
    $nom = (int)$x['nominal'];
    $tx[] = [
        [excel_date($x['tanggal']), ST_DATE],
        [$x['nama'], ST_DEFAULT],
        [$x['jenis'] === 'setor' ? 'Setor' : 'Tarik', ST_DEFAULT],
        [$nom, ST_NUMBER],
        [['f' => 'IF(C' . $r . '="Setor",D' . $r . ',-D' . $r . ')', 'v' => $x['jenis'] === 'setor' ? $nom : -$nom], ST_NUMBER],
        [$x['keterangan'], ST_DEFAULT],
        [$x['metode'] === 'cash' ? 'Cash' : 'Saldo', ST_DEFAULT],
    ];
}

/* ---------- Sheet 3: Pemindahan ---------- */

$pd = [[$H('Tanggal'), $H('Target'), $H('Dari'), $H('Ke'), $H('Nominal (Rp)'), $H('Keterangan')]];
foreach ($pindahan as $p) {
    $pd[] = [
        [excel_date($p['tanggal']), ST_DATE],
        [$p['nama'], ST_DEFAULT],
        [$p['dari'] === 'saldo' ? 'Saldo' : 'Cash', ST_DEFAULT],
        [$p['dari'] === 'saldo' ? 'Cash' : 'Saldo', ST_DEFAULT],
        [(int)$p['nominal'], ST_NUMBER],
        [$p['keterangan'], ST_DEFAULT],
    ];
}

$sheet1 = sheet_xml($ringkasan, [28, 18, 18, 18, 11, 16, 12, 16, 16]);
$sheet2 = sheet_xml($tx, [14, 28, 10, 16, 20, 40, 14]);
$sheet3 = sheet_xml($pd, [14, 28, 10, 10, 16, 40]);

/* ---------- Bagian statis XLSX ---------- */

$contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    . '<Default Extension="xml" ContentType="application/xml"/>'
    . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet2.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/worksheets/sheet3.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
    . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
    . '</Types>';

$rootRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
    . '</Relationships>';

$workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
    . '<sheets><sheet name="Ringkasan" sheetId="1" r:id="rId1"/><sheet name="Transaksi" sheetId="2" r:id="rId2"/><sheet name="Pemindahan" sheetId="3" r:id="rId4"/></sheets>'
    . '<calcPr fullCalcOnLoad="1"/>'
    . '</workbook>';

$workbookRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
    . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet2.xml"/>'
    . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
    . '<Relationship Id="rId4" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet3.xml"/>'
    . '</Relationships>';

$styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<numFmts count="1"><numFmt numFmtId="164" formatCode="dd/mm/yyyy"/></numFmts>'
    . '<fonts count="3">'
    .   '<font><sz val="11"/><name val="Calibri"/></font>'
    .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
    .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
    . '</fonts>'
    . '<fills count="3">'
    .   '<fill><patternFill patternType="none"/></fill>'
    .   '<fill><patternFill patternType="gray125"/></fill>'
    .   '<fill><patternFill patternType="solid"><fgColor rgb="FF0F766E"/><bgColor indexed="64"/></patternFill></fill>'
    . '</fills>'
    . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
    . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
    . '<cellXfs count="7">'
    .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
    .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
    .   '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    .   '<xf numFmtId="10" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
    .   '<xf numFmtId="3" fontId="2" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
    .   '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
    . '</cellXfs>'
    . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
    . '</styleSheet>';

/* ---------- Susun ZIP & kirim ---------- */

$tmp = tempnam(sys_get_temp_dir(), 'xlsx');
$zip = new ZipArchive();
if ($zip->open($tmp, ZipArchive::OVERWRITE | ZipArchive::CREATE) !== true) {
    die('Gagal membuat file Excel.');
}
$zip->addFromString('[Content_Types].xml', $contentTypes);
$zip->addFromString('_rels/.rels', $rootRels);
$zip->addFromString('xl/workbook.xml', $workbook);
$zip->addFromString('xl/_rels/workbook.xml.rels', $workbookRels);
$zip->addFromString('xl/styles.xml', $styles);
$zip->addFromString('xl/worksheets/sheet1.xml', $sheet1);
$zip->addFromString('xl/worksheets/sheet2.xml', $sheet2);
$zip->addFromString('xl/worksheets/sheet3.xml', $sheet3);
$zip->close();

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="' . $namaFile . '.xlsx"');
header('Content-Length: ' . filesize($tmp));
header('Cache-Control: no-store');
readfile($tmp);
unlink($tmp);
exit;
