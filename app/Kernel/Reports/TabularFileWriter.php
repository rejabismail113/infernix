<?php

declare(strict_types=1);

namespace App\Kernel\Reports;

use App\Kernel\Secrets\SecretRedactor;
use RuntimeException;
use ZipArchive;

/**
 * Writes a report table to CSV or a minimal XLSX (Office Open XML via ZipArchive — no extra dependency).
 * Every cell is passed through the SecretRedactor (UAT-08) and guarded against spreadsheet formula injection
 * (cells starting with = + - @ are prefixed with an apostrophe unless they are plain numbers).
 *
 * @see SRS M11-05, UAT-08, NFR-02
 */
final class TabularFileWriter
{
    public function __construct(private readonly SecretRedactor $secrets) {}

    /**
     * @param  list<string>  $headers
     * @param  list<list<string|int|null>>  $rows
     * @return string path of the written temp file
     */
    public function write(string $format, array $headers, array $rows, string $sheet = 'Report'): string
    {
        $clean = array_map(fn (array $r): array => array_map(fn ($v): string => $this->cell($v), $r), $rows);
        $head = array_map(fn ($v): string => $this->cell($v), $headers);

        return match ($format) {
            'csv' => $this->csv($head, $clean),
            'xlsx' => $this->xlsx($head, $clean, $sheet),
            default => throw new RuntimeException("Unsupported export format [{$format}]."),
        };
    }

    public function cell(string|int|null $value): string
    {
        $v = $this->secrets->scrub((string) ($value ?? ''));
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($v)) {
            $v = "'".$v;
        }

        return $v;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'exp');
        $out = fopen($path, 'w') ?: throw new RuntimeException('Cannot write export.');
        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel opens Malay/English text correctly
        fputcsv($out, $headers);
        foreach ($rows as $r) {
            fputcsv($out, $r);
        }
        fclose($out);

        return $path;
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function xlsx(array $headers, array $rows, string $sheet): string
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'exp');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot write export.');
        }
        $xmlRows = '';
        foreach (array_merge([$headers], $rows) as $i => $r) {
            $cells = '';
            foreach (array_values($r) as $c => $v) {
                $ref = $this->col($c).($i + 1);
                $cells .= preg_match('/^-?\d{1,15}(\.\d{1,4})?$/', $v) === 1 && $i > 0
                    ? '<c r="'.$ref.'"><v>'.$v.'</v></c>'
                    : '<c r="'.$ref.'" t="inlineStr"'.($i === 0 ? ' s="1"' : '').'><is><t xml:space="preserve">'.htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</t></is></c>';
            }
            $xmlRows .= '<row r="'.($i + 1).'">'.$cells.'</row>';
        }
        $name = htmlspecialchars(mb_substr(preg_replace('/[\[\]\*\?\/\\\\:]/', ' ', $sheet) ?? 'Report', 0, 31), ENT_XML1);
        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="'.$name.'" sheetId="1" r:id="rId1"/></sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs><cellXfs count="2"><xf/><xf fontId="1" applyFont="1"/></cellXfs></styleSheet>');
        $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$xmlRows.'</sheetData></worksheet>');
        $zip->close();

        return $path;
    }

    private function col(int $i): string
    {
        $s = '';
        for ($n = $i + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $s = chr(65 + (($n - 1) % 26)).$s;
        }

        return $s;
    }
}
