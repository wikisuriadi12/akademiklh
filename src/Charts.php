<?php
declare(strict_types=1);

/** Grafik SVG sederhana tanpa pustaka luar; warna diatur lewat CSS (mendukung mode gelap). */
final class Charts
{
    /** @param array<string,int> $data label => nilai */
    public static function bars(array $data, string $ariaLabel = 'Grafik batang'): string
    {
        $n = count($data);
        if ($n === 0) { return ''; }
        $w = 560; $h = 220; $padT = 28; $padB = 28; $padX = 12;
        $max = max(1, max($data));
        $slot = ($w - 2 * $padX) / $n;
        $bw = min(46, $slot * 0.58);
        $ch = $h - $padT - $padB;
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" role="img" aria-label="' . e($ariaLabel) . '" preserveAspectRatio="xMidYMid meet">';
        foreach ([0.25, 0.5, 0.75, 1] as $g) {
            $y = $padT + $ch - $ch * $g;
            $svg .= '<line class="grid" x1="' . $padX . '" x2="' . ($w - $padX) . '" y1="' . round($y, 1) . '" y2="' . round($y, 1) . '"/>';
        }
        $i = 0;
        $last = $n - 1;
        foreach ($data as $label => $v) {
            $x = $padX + $slot * $i + ($slot - $bw) / 2;
            $bh = $v > 0 ? max(3, $ch * $v / $max) : 0;
            $y = $padT + $ch - $bh;
            $svg .= '<rect class="bar' . ($i === $last ? ' bar-now' : '') . '" x="' . round($x, 1) . '" y="' . round($y, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '" rx="5"><title>' . e($label) . ': ' . e(rp($v)) . '</title></rect>';
            if ($v > 0) {
                $svg .= '<text class="val" x="' . round($x + $bw / 2, 1) . '" y="' . round($y - 7, 1) . '" text-anchor="middle">' . e(rp_short($v)) . '</text>';
            }
            $svg .= '<text class="lbl" x="' . round($x + $bw / 2, 1) . '" y="' . ($h - 8) . '" text-anchor="middle">' . e((string) $label) . '</text>';
            $i++;
        }
        return $svg . '</svg>';
    }

    public static function donut(float $persen, string $label): string
    {
        $persen = max(0.0, min(100.0, $persen));
        $r = 52; $c = 2 * M_PI * $r;
        $dash = $c * $persen / 100;
        return '<svg class="donut" viewBox="0 0 140 140" role="img" aria-label="' . e($label . ' ' . round($persen) . ' persen') . '">'
            . '<circle class="d-track" cx="70" cy="70" r="' . $r . '" fill="none" stroke-width="14"/>'
            . '<circle class="d-val" cx="70" cy="70" r="' . $r . '" fill="none" stroke-width="14" stroke-linecap="round" stroke-dasharray="' . round($dash, 2) . ' ' . round($c, 2) . '" transform="rotate(-90 70 70)"/>'
            . '<text class="d-num" x="70" y="72" text-anchor="middle">' . round($persen) . '%</text>'
            . '<text class="d-lbl" x="70" y="92" text-anchor="middle">' . e($label) . '</text></svg>';
    }

    /** Batang horizontal: label => nilai. Mengembalikan HTML (bukan SVG) agar rapi di layar kecil. */
    public static function hbars(array $data, callable $fmt): string
    {
        $max = max(1, $data ? max($data) : 1);
        $h = '<div class="hbars">';
        foreach ($data as $label => $v) {
            $pct = round($v / $max * 100);
            $h .= '<div class="hb"><span class="hb-l">' . e((string) $label) . '</span><span class="hb-t"><i style="width:' . $pct . '%"></i></span><span class="hb-v">' . e($fmt($v)) . '</span></div>';
        }
        return $h . '</div>';
    }
}
