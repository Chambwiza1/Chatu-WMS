<?php

namespace App\Libraries\Core;

/**
 * .xlsx files without packages (core 1.9, D6): one sheet, a bold header row that stays in view, a filter on it,
 * numbers as numbers (so they add up in Excel), dates as text, and columns wide enough for their contents.
 * Text that looks like a formula is written as text, never as a formula.
 */
final class Xlsx
{
    /** The file's bytes. $rows: lists of values in the order of $header. */
    public static function build(string $sheet, array $header, array $rows): string
    {
        $sheet = mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $sheet) ?: 'Sheet1', 0, 31);
        $cols  = count($header);
        $width = array_map(static fn ($h) => min(60, max(8, mb_strlen((string) $h) + 2)), array_values($header));
        $xml   = '';
        $r     = 1;
        $xml  .= self::row($r++, array_values($header), true, $width);
        foreach ($rows as $row) {
            $xml .= self::row($r++, array_values($row), false, $width);
        }
        $last = self::col(max(1, $cols)) . max(1, $r - 1);
        $colsXml = '';
        foreach ($width as $i => $w) {
            $colsXml .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<dimension ref="A1:' . $last . '"/>'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>'
            . ($colsXml ? '<cols>' . $colsXml . '</cols>' : '')
            . '<sheetData>' . $xml . '</sheetData>'
            . ($cols ? '<autoFilter ref="A1:' . $last . '"/>' : '')
            . '</worksheet>';
        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>',
            'docProps/core.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' . self::esc($sheet) . '</dc:title><dc:creator>Chatu Warehouse</dc:creator><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>',
            'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . self::esc($sheet) . '" sheetId="1" r:id="rId1"/></sheets>' . ($cols ? '<definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . str_replace("'", "''", self::esc($sheet)) . '\'!$A$1:$' . self::col($cols) . '$' . max(1, $r - 1) . '</definedName></definedNames>' : '') . '</workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7F0E8"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>',
            'xl/worksheets/sheet1.xml' => $sheetXml,
        ];
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $body) {
            $zip->addFromString($name, $body);
        }
        $zip->close();
        $out = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $out;
    }

    private static function row(int $r, array $cells, bool $head, array &$width): string
    {
        $x = '<row r="' . $r . '">';
        foreach ($cells as $i => $v) {
            $ref = self::col($i + 1) . $r;
            $s   = $head ? ' s="1"' : '';
            if (! $head && (is_int($v) || is_float($v) || (is_string($v) && preg_match('/^-?(0|[1-9]\d{0,14})(\.\d{1,6})?$/', $v)))) {
                $x .= '<c r="' . $ref . '"' . $s . '><v>' . $v . '</v></c>';
            } elseif ($v === null || $v === '') {
                continue;
            } else {
                $t  = (string) $v;
                $x .= '<c r="' . $ref . '"' . $s . ' t="inlineStr"><is><t xml:space="preserve">' . self::esc($t) . '</t></is></c>';
            }
            if (isset($width[$i])) {
                $width[$i] = min(60, max($width[$i], mb_strlen((string) $v) + 2));
            }
        }
        return $x . '</row>';
    }

    /** 1 → A, 27 → AA. */
    public static function col(int $n): string
    {
        $s = '';
        while ($n > 0) {
            $m = ($n - 1) % 26;
            $s = chr(65 + $m) . $s;
            $n = intdiv($n - 1, 26);
        }
        return $s;
    }

    private static function esc(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $s);
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
