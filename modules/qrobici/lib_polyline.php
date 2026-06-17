<?php
/**
 * QroBici Analytics — Polilíneas
 * ------------------------------------------------------------
 * Decodifica el formato "encoded polyline algorithm" de Google
 * (campo RECORRIDO de la vista viajes) y simplifica el trazo
 * con Douglas-Peucker para aligerar el JSON enviado al cliente.
 */

/**
 * Decodifica una polilínea codificada de Google a un array de [lat, lng].
 */
function qrb_polyline_decode(?string $encoded): array
{
    if ($encoded === null || $encoded === '' || strtoupper($encoded) === 'NULL') {
        return [];
    }
    $coords = [];
    $index = 0; $len = strlen($encoded);
    $lat = 0; $lng = 0;

    while ($index < $len) {
        foreach (['lat', 'lng'] as $unit) {
            $shift = 0; $result = 0;
            do {
                if ($index >= $len) { return $coords; }
                $b = ord($encoded[$index]) - 63;
                $index++;
                $result |= ($b & 0x1f) << $shift;
                $shift += 5;
            } while ($b >= 0x20);
            $d = ($result & 1) ? ~($result >> 1) : ($result >> 1);
            if ($unit === 'lat') { $lat += $d; } else { $lng += $d; }
        }
        $coords[] = [round($lat * 1e-5, 6), round($lng * 1e-5, 6)];
    }
    return $coords;
}

/**
 * Distancia perpendicular punto-recta (en grados, suficiente para simplificación local).
 */
function qrb_perp_dist(array $pt, array $start, array $end): float
{
    if ($start === $end) {
        return sqrt(pow($pt[0]-$start[0], 2) + pow($pt[1]-$start[1], 2));
    }
    [$x0,$y0] = $pt; [$x1,$y1] = $start; [$x2,$y2] = $end;
    $num = abs(($y2-$y1)*$x0 - ($x2-$x1)*$y0 + $x2*$y1 - $y2*$x1);
    $den = sqrt(pow($y2-$y1, 2) + pow($x2-$x1, 2));
    return $den == 0 ? 0 : $num / $den;
}

/**
 * Simplificación Douglas-Peucker iterativa (sin recursión profunda).
 * epsilon ≈ 0.00003 grados ≈ 3 m, preserva la forma de la calle.
 */
function qrb_rdp(array $points, float $epsilon = 0.00003): array
{
    $n = count($points);
    if ($n < 3) { return $points; }

    $keep = array_fill(0, $n, false);
    $keep[0] = true; $keep[$n-1] = true;
    $stack = [[0, $n-1]];

    while ($stack) {
        [$first, $last] = array_pop($stack);
        $dmax = 0; $index = 0;
        for ($i = $first + 1; $i < $last; $i++) {
            $d = qrb_perp_dist($points[$i], $points[$first], $points[$last]);
            if ($d > $dmax) { $dmax = $d; $index = $i; }
        }
        if ($dmax > $epsilon && $index > 0) {
            $keep[$index] = true;
            $stack[] = [$first, $index];
            $stack[] = [$index, $last];
        }
    }

    $out = [];
    for ($i = 0; $i < $n; $i++) {
        if ($keep[$i]) {
            $out[] = [round($points[$i][0], 5), round($points[$i][1], 5)];
        }
    }
    return $out;
}

/**
 * Decodifica + simplifica en un solo paso.
 */
function qrb_decodifica_y_simplifica(?string $encoded): array
{
    $pts = qrb_polyline_decode($encoded);
    if (count($pts) < 8) { return $pts; }
    return qrb_rdp($pts, 0.00003);
}
