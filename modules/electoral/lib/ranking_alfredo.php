<?php
/**
 * Ranking "Alfredo" — dato externo cargado desde un JSON desacoplado
 * (modules/electoral/data/ranking_alfredo.json), indexado por num_seccion.
 *
 * Columnas del Excel original: SECCIÓN, DELEGACIÓN, RANK, 21-24, IDENTIDAD,
 * COLONIAS Y LOCALIDADES. Es información que puede retirarse fácilmente:
 * basta borrar el JSON (el loader devuelve null) y quitar el bloque en la UI.
 */
declare(strict_types=1);

function ranking_alfredo_map(): array {
    static $all = null;
    if ($all !== null) return $all;
    $all = [];
    $f = __DIR__ . '/../data/ranking_alfredo.json';
    if (is_file($f)) {
        $j = json_decode((string)@file_get_contents($f), true);
        if (is_array($j)) $all = $j;
    }
    return $all;
}

/** Devuelve el registro del ranking para una sección, o null si no existe. */
function ranking_alfredo_seccion(int $s): ?array {
    return ranking_alfredo_map()[(string)$s] ?? null;
}
