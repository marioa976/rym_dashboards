<?php
/**
 * Lector mínimo de XLSX (sin Composer ni PhpSpreadsheet).
 * Usa ZipArchive + SimpleXML — un XLSX es solo un .zip con XMLs.
 *
 * Uso:
 *   $x = new SimpleXlsx('/ruta.xlsx');
 *   foreach ($x->sheets() as $name => $sheetPath) {
 *       foreach ($x->readSheetByPath($sheetPath) as $row) { ... }
 *   }
 *
 * Limitaciones intencionales:
 *   - Lee solo valores (números y strings vía sharedStrings).
 *   - No interpreta fórmulas — toma el valor cacheado si existe.
 *   - No procesa estilos, formatos, fechas Excel ni hipervínculos.
 *   Suficiente para importar tablas planas como las de "META … MAYO 2.xlsx".
 */

class SimpleXlsx
{
    private ZipArchive $zip;
    /** @var string[] */
    private array $sharedStrings = [];
    /** @var array<string,string> name → sheet xml path */
    private array $sheets = [];

    public function __construct(string $path)
    {
        if (!is_readable($path)) {
            throw new RuntimeException("XLSX no legible: $path");
        }
        $this->zip = new ZipArchive();
        if ($this->zip->open($path) !== true) {
            throw new RuntimeException("No se pudo abrir XLSX: $path");
        }
        $this->loadSharedStrings();
        $this->loadSheets();
    }

    public function __destruct()
    {
        $this->zip->close();
    }

    private function loadSharedStrings(): void
    {
        $xml = $this->zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) return;
        $doc = simplexml_load_string($xml);
        if (!$doc) return;
        foreach ($doc->si as $si) {
            // String simple
            if (isset($si->t)) {
                $this->sharedStrings[] = (string)$si->t;
            } else {
                // Rich text: concatenamos los runs
                $concat = '';
                foreach ($si->r as $r) { $concat .= (string)$r->t; }
                $this->sharedStrings[] = $concat;
            }
        }
    }

    private function loadSheets(): void
    {
        // workbook.xml lista los sheets con r:id, y workbook.xml.rels mapea
        // r:id → ruta del archivo.
        $wb = $this->zip->getFromName('xl/workbook.xml');
        $rels = $this->zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb === false || $rels === false) return;

        $relsDoc = simplexml_load_string($rels);
        $relMap = [];
        foreach ($relsDoc->Relationship as $rel) {
            $relMap[(string)$rel['Id']] = 'xl/' . ltrim((string)$rel['Target'], '/');
        }

        $wbDoc = simplexml_load_string($wb);
        $wbDoc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $wbDoc->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');

        foreach ($wbDoc->xpath('//m:sheets/m:sheet') as $s) {
            $name = (string)$s['name'];
            $rid  = (string)$s->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
            if (isset($relMap[$rid])) {
                $this->sheets[$name] = $relMap[$rid];
            }
        }
    }

    /** @return array<string,string> nombre → path interno */
    public function sheets(): array
    {
        return $this->sheets;
    }

    /**
     * Convierte una letra de columna (A, B, …, AA) a índice 0-based.
     */
    private static function colToIdx(string $col): int
    {
        $col = strtoupper($col);
        $idx = 0;
        for ($i = 0, $n = strlen($col); $i < $n; $i++) {
            $idx = $idx * 26 + (ord($col[$i]) - ord('A') + 1);
        }
        return $idx - 1;
    }

    /**
     * Lee una hoja por su ruta interna. Devuelve un array de filas;
     * cada fila es un array indexado por número de columna (0-based)
     * con strings/números como valor.
     */
    public function readSheetByPath(string $path): array
    {
        $xml = $this->zip->getFromName($path);
        if ($xml === false) return [];
        $doc = simplexml_load_string($xml);
        if (!$doc) return [];
        $doc->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');

        $rows = [];
        foreach ($doc->xpath('//m:sheetData/m:row') as $r) {
            $row = [];
            foreach ($r->c as $c) {
                $ref  = (string)$c['r'];               // ej. "B3"
                $colL = preg_replace('/[0-9]+/', '', $ref);
                $idx  = self::colToIdx($colL);
                $type = (string)$c['t'];               // 's', 'b', 'str', vacío = número
                $val  = (string)($c->v ?? '');
                if ($val === '' && isset($c->is->t)) {
                    $val = (string)$c->is->t;
                }
                if ($type === 's') {
                    $val = $this->sharedStrings[(int)$val] ?? '';
                } elseif ($type === 'b') {
                    $val = $val === '1' ? 'TRUE' : 'FALSE';
                }
                $row[$idx] = $val;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Lee una hoja por nombre.
     */
    public function readSheet(string $name): array
    {
        if (!isset($this->sheets[$name])) return [];
        return $this->readSheetByPath($this->sheets[$name]);
    }
}
