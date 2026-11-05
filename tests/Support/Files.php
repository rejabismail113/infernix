<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Http\UploadedFile;
use ZipArchive;

/** Real file fixtures (genuine magic bytes) for Document Centre tests. */
final class Files
{
    public static function pdf(string $name = 'receipt.pdf', string $marker = 'hello'): UploadedFile
    {
        $body = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n1 0 obj\n<< /Type /Catalog /Marker ({$marker}) >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";

        return UploadedFile::fake()->createWithContent($name, $body);
    }

    public static function png(string $name = 'photo.png'): UploadedFile
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagepng($img);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    public static function jpg(string $name = 'scan.jpg'): UploadedFile
    {
        $img = imagecreatetruecolor(4, 4);
        ob_start();
        imagejpeg($img);

        return UploadedFile::fake()->createWithContent($name, (string) ob_get_clean());
    }

    public static function csv(string $name = 'list.csv', string $body = "name,amount\nAli,10.00\n"): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $body);
    }

    /** @param  array<string, string>  $extraEntries */
    public static function ooxml(string $name, string $partDir, array $extraEntries = [], bool $withContentTypes = true): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ooxml');
        $zip = new ZipArchive;
        $zip->open($tmp, ZipArchive::OVERWRITE);
        if ($withContentTypes) {
            $zip->addFromString('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');
        }
        $zip->addFromString('_rels/.rels', '<?xml version="1.0"?><Relationships/>');
        $zip->addFromString($partDir.($partDir === 'xl/' ? 'workbook.xml' : 'document.xml'), '<?xml version="1.0"?><root/>');
        foreach ($extraEntries as $entry => $content) {
            $zip->addFromString($entry, $content);
        }
        $zip->close();

        return UploadedFile::fake()->createWithContent($name, (string) file_get_contents($tmp));
    }

    public static function xlsx(string $name = 'budget.xlsx'): UploadedFile
    {
        return self::ooxml($name, 'xl/');
    }

    public static function docx(string $name = 'contract.docx'): UploadedFile
    {
        return self::ooxml($name, 'word/');
    }

    public static function raw(string $name, string $content): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $content);
    }
}
