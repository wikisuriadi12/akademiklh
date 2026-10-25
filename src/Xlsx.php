<?php
declare(strict_types=1);

/** Pembaca dan penulis .xlsx sederhana (tanpa pustaka luar): satu atau beberapa lembar berisi teks/angka. */
final class Xlsx
{
    /** Baca lembar pertama menjadi baris-baris string. */
    public static function baca(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) { throw new InvalidArgumentException('Berkas Excel tidak dapat dibuka.'); }
        $shared = [];
        $ss = $zip->getFromName('xl/sharedStrings.xml');
        if ($ss !== false) {
            $x = @simplexml_load_string($ss);
            if ($x) { foreach ($x->si as $si) { $t = ''; if (isset($si->t)) { $t = (string) $si->t; } else { foreach ($si->r as $r) { $t .= (string) $r->t; } } $shared[] = $t; } }
        }
        $nama = 'xl/worksheets/sheet1.xml';
        $wb = $zip->getFromName('xl/workbook.xml'); $rel = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rel !== false) {
            $w = @simplexml_load_string($wb); $r = @simplexml_load_string($rel);
            if ($w && $r && isset($w->sheets->sheet[0])) {
                $rid = (string) $w->sheets->sheet[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
                foreach ($r->Relationship as $rr) { if ((string) $rr['Id'] === $rid) { $tg = (string) $rr['Target']; $nama = 'xl/' . ltrim(preg_replace('#^/?(xl/)?#', '', $tg), '/'); } }
            }
        }
        $sh = $zip->getFromName($nama);
        $zip->close();
        if ($sh === false) { throw new InvalidArgumentException('Lembar pertama tidak ditemukan di berkas Excel.'); }
        $s = @simplexml_load_string($sh);
        if (!$s) { throw new InvalidArgumentException('Isi lembar Excel tidak terbaca.'); }
        $rows = [];
        foreach ($s->sheetData->row ?? [] as $row) {
            $line = [];
            foreach ($row->c as $c) {
                $ref = (string) $c['r']; $col = 0;
                foreach (str_split(preg_replace('/\d+/', '', $ref)) as $ch) { $col = $col * 26 + (ord($ch) - 64); }
                $col = max(0, $col - 1);
                $type = (string) $c['t'];
                if ($type === 's') { $v = $shared[(int) $c->v] ?? ''; }
                elseif ($type === 'inlineStr') { $v = (string) ($c->is->t ?? ''); }
                else { $v = (string) $c->v; if ($type === '' && $v !== '' && is_numeric($v)) { $f = (float) $v; $v = $f == floor($f) && abs($f) < 1e15 ? (string) (int) $f : rtrim(rtrim(number_format($f, 6, '.', ''), '0'), '.'); } }
                while (count($line) < $col) { $line[] = ''; }
                $line[$col] = trim($v);
            }
            $rows[] = $line;
        }
        return $rows;
    }

    /** Buat berkas .xlsx. $lembar: ['Nama lembar' => [[baris...], ...]]. Baris pertama dicetak tebal. Mengembalikan isi biner. */
    public static function tulis(array $lembar, array $lebar = []): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xl');
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        $esc = static fn(string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wbs = ''; $rels = '';
        $i = 0;
        foreach ($lembar as $nama => $rows) {
            $i++;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wbs .= '<sheet name="' . $esc(mb_substr((string) $nama, 0, 31)) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
            $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
            $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
            if (!empty($lebar[$nama])) { $x .= '<cols>'; foreach ($lebar[$nama] as $k => $w) { $x .= '<col min="' . ($k + 1) . '" max="' . ($k + 1) . '" width="' . $w . '" customWidth="1"/>'; } $x .= '</cols>'; }
            $x .= '<sheetData>';
            foreach ($rows as $ri => $row) {
                $x .= '<row r="' . ($ri + 1) . '">';
                foreach (array_values($row) as $ci => $v) {
                    $ref = '';
                    for ($n = $ci + 1; $n > 0; $n = intdiv($n - 1, 26)) { $ref = chr(65 + ($n - 1) % 26) . $ref; }
                    $ref .= ($ri + 1);
                    $st = $ri === 0 ? ' s="1"' : '';
                    if (is_int($v) || is_float($v)) { $x .= '<c r="' . $ref . '"' . $st . '><v>' . $v . '</v></c>'; }
                    else { $x .= '<c r="' . $ref . '" t="inlineStr"' . $st . '><is><t xml:space="preserve">' . $esc((string) $v) . '</t></is></c>'; }
                }
                $x .= '</row>';
            }
            $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $x . '</sheetData></worksheet>');
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>' . $wbs . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels . '<Relationship Id="rId' . ($i + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDCEFE6"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->close();
        $bin = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $bin;
    }

    /** Baca CSV (koma atau titik koma) menjadi baris-baris. */
    public static function bacaCsv(string $path): array
    {
        $raw = (string) file_get_contents($path);
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        $first = strtok($raw, "\n") ?: '';
        $d = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $h = fopen('php://memory', 'r+'); fwrite($h, $raw); rewind($h);
        $rows = [];
        while (($r = fgetcsv($h, 0, $d, '"', '\\')) !== false) { if ($r === [null]) { continue; } $rows[] = array_map(static fn($v) => trim((string) $v), $r); }
        fclose($h);
        return $rows;
    }
}
