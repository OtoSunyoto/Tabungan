<?php
/**
 * Grafik SVG buatan sendiri (tanpa library / internet).
 * Warna diatur lewat CSS (kelas .ch-*) agar ikut mode terang/gelap.
 */

/** 1500000 -> "1,5 jt", 250000 -> "250 rb" */
function fmt_singkat($n): string
{
    $n = (float)$n;
    $a = abs($n);
    if ($a >= 1e9)      { $v = $n / 1e9; $s = ' M'; }
    elseif ($a >= 1e6)  { $v = $n / 1e6; $s = ' jt'; }
    elseif ($a >= 1e3)  { $v = $n / 1e3; $s = ' rb'; }
    else return (string)(int)round($n);
    return rtrim(rtrim(number_format($v, 1, ',', ''), '0'), ',') . $s;
}

/** Skala sumbu Y dengan 4 interval rapi: [batasAtas, langkah] */
function nice_scale(float $max): array
{
    if ($max <= 0) return [4.0, 1.0];
    $raw = $max / 4;
    $p = 10 ** floor(log10($raw));
    foreach ([1, 2, 2.5, 5, 10] as $f) {
        if ($f * $p >= $raw) {
            $step = $f * $p;
            break;
        }
    }
    return [$step * 4, $step];
}

function chart_grid(float $yMax, float $step, int $W, int $L, int $R, int $T, int $ph): string
{
    $out = '';
    for ($i = 0; $i <= 4; $i++) {
        $y = $T + $ph - $ph * $i / 4;
        $out .= '<line class="ch-grid" stroke="#e5e9f2" stroke-width="1" x1="' . $L . '" y1="' . round($y, 1) . '" x2="' . ($W - $R) . '" y2="' . round($y, 1) . '"/>';
        $out .= '<text class="ch-txt" fill="#64748b" font-size="11" x="' . ($L - 8) . '" y="' . round($y + 4, 1) . '" text-anchor="end">' . e(fmt_singkat($step * $i)) . '</text>';
    }
    return $out;
}

/** Batang berpasangan per bulan: setor vs tarik */
function chart_bars(array $labels, array $setor, array $tarik): string
{
    $n = count($labels);
    $max = max(array_merge($setor, $tarik, [0]));
    if ($n === 0 || $max <= 0) {
        return '<div class="empty small">Belum ada transaksi pada periode ini.</div>';
    }
    $W = 720; $H = 260; $L = 54; $R = 12; $T = 18; $B = 34;
    $pw = $W - $L - $R;
    $ph = $H - $T - $B;
    [$yMax, $step] = nice_scale((float)$max);

    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Grafik setoran dan penarikan per bulan">';
    $svg .= chart_grid($yMax, $step, $W, $L, $R, $T, $ph);

    $gw = $pw / $n;
    $bw = min(34, $gw * 0.28);
    $base = $T + $ph;
    for ($i = 0; $i < $n; $i++) {
        $cx = $L + $gw * ($i + 0.5);
        foreach ([['ch-in', $setor[$i], $cx - $bw - 2, 'Setor'], ['ch-out', $tarik[$i], $cx + 2, 'Tarik']] as [$cls, $v, $x, $nm]) {
            if ($v <= 0) continue;
            $h = max(2, $v / $yMax * $ph);
            $svg .= '<rect class="' . $cls . '" fill="' . ($cls === 'ch-in' ? '#6366f1' : '#f87171') . '" x="' . round($x, 1) . '" y="' . round($base - $h, 1) . '" width="' . round($bw, 1) . '" height="' . round($h, 1) . '" rx="5">'
                  . '<title>' . e($labels[$i] . ' - ' . $nm . ': ' . rupiah($v)) . '</title></rect>';
        }
        if ($setor[$i] > 0) {
            $h = max(2, $setor[$i] / $yMax * $ph);
            $svg .= '<text class="ch-val" fill="#0f172a" font-size="10.5" font-weight="700" x="' . round($cx - $bw / 2 - 2, 1) . '" y="' . round($base - $h - 6, 1) . '" text-anchor="middle">' . e(fmt_singkat($setor[$i])) . '</text>';
        }
        $svg .= '<text class="ch-txt" fill="#64748b" font-size="11" x="' . round($cx, 1) . '" y="' . ($H - 10) . '" text-anchor="middle">' . e($labels[$i]) . '</text>';
    }
    return $svg . '</svg>';
}

/**
 * Garis perkembangan saldo + garis target.
 * $points: [['d' => 'Y-m-d', 'v' => saldoKumulatif], ...] urut tanggal.
 */
function chart_saldo(array $points, int $target): string
{
    if (!$points) {
        return '<div class="empty small">Grafik akan muncul setelah ada transaksi.</div>';
    }
    $W = 720; $H = 260; $L = 54; $R = 14; $T = 18; $B = 34;
    $pw = $W - $L - $R;
    $ph = $H - $T - $B;

    $day = fn(string $d): float => strtotime($d . ' 00:00:00 UTC') / 86400;
    $xmin = $day($points[0]['d']);
    $xmax = $day($points[count($points) - 1]['d']);
    if ($xmax - $xmin < 1) { $xmin -= 1; $xmax += 1; }

    $vmax = max($target, ...array_map(fn($p) => max(0, $p['v']), $points));
    [$yMax, $step] = nice_scale((float)$vmax);

    $X = fn(string $d): float => $L + ($day($d) - $xmin) / ($xmax - $xmin) * $pw;
    $Y = fn($v): float => $T + $ph - max(0, min($v, $yMax)) / $yMax * $ph;

    $svg = '<svg class="chart" viewBox="0 0 ' . $W . ' ' . $H . '" role="img" aria-label="Grafik perkembangan saldo tabungan">';
    $svg .= chart_grid($yMax, $step, $W, $L, $R, $T, $ph);

    $base = $T + $ph;
    $line = '';
    foreach ($points as $i => $p) {
        $line .= ($i ? ' L ' : 'M ') . round($X($p['d']), 1) . ' ' . round($Y($p['v']), 1);
    }
    $x0 = round($X($points[0]['d']), 1);
    $x1 = round($X($points[count($points) - 1]['d']), 1);
    $svg .= '<path class="ch-area" fill="#6366f1" fill-opacity="0.16" stroke="none" d="' . $line . ' L ' . $x1 . ' ' . $base . ' L ' . $x0 . ' ' . $base . ' Z"/>';

    // garis target
    $ty = round($Y($target), 1);
    $svg .= '<line class="ch-target" stroke="#16a34a" stroke-width="1.6" stroke-dasharray="6 5" x1="' . $L . '" y1="' . $ty . '" x2="' . ($W - $R) . '" y2="' . $ty . '"/>';
    $svg .= '<text class="ch-target-txt" fill="#16a34a" font-size="11" font-weight="700" x="' . ($W - $R) . '" y="' . ($ty - 6) . '" text-anchor="end">Target ' . e(rupiah($target)) . '</text>';

    $svg .= '<path class="ch-line" fill="none" stroke="#6366f1" stroke-width="3" stroke-linejoin="round" stroke-linecap="round" d="' . $line . '"/>';
    if (count($points) <= 60) {
        foreach ($points as $p) {
            $svg .= '<circle class="ch-dot" fill="#ffffff" stroke="#6366f1" stroke-width="2.5" cx="' . round($X($p['d']), 1) . '" cy="' . round($Y($p['v']), 1) . '" r="4"><title>'
                  . e(tgl($p['d']) . ': ' . rupiah($p['v'])) . '</title></circle>';
        }
    }

    $svg .= '<text class="ch-txt" fill="#64748b" font-size="11" x="' . $x0 . '" y="' . ($H - 10) . '" text-anchor="start">' . e(tgl($points[0]['d'])) . '</text>';
    if ($x1 - $x0 > 120) {
        $svg .= '<text class="ch-txt" fill="#64748b" font-size="11" x="' . $x1 . '" y="' . ($H - 10) . '" text-anchor="end">' . e(tgl($points[count($points) - 1]['d'])) . '</text>';
    }
    return $svg . '</svg>';
}

/** Saldo kumulatif per hari dari transaksi urut naik; tambahkan titik "hari ini". */
function saldo_points(array $txsAsc): array
{
    $saldo = 0;
    $perHari = [];
    foreach ($txsAsc as $x) {
        $saldo += $x['jenis'] === 'setor' ? (int)$x['nominal'] : -(int)$x['nominal'];
        $perHari[$x['tanggal']] = $saldo;
    }
    $pts = [];
    foreach ($perHari as $d => $v) $pts[] = ['d' => $d, 'v' => $v];
    $today = date('Y-m-d');
    if ($pts && end($pts)['d'] < $today) {
        $pts[] = ['d' => $today, 'v' => end($pts)['v']];
    }
    return $pts;
}
