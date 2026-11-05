<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services;

use App\Modules\DocumentCentre\Exceptions\DocumentRejected;
use finfo;
use Illuminate\Http\UploadedFile;
use ZipArchive;

/**
 * Validates an upload BY CONTENT: extension allow-list, sniffed MIME must match the extension,
 * size limits, OOXML structure for xlsx/docx. The client-supplied MIME type is never trusted.
 *
 * @see SRS G-07, M06-03
 */
final class FileInspector
{
    /** @return array{extension: string, mime: string, size: int, hash: string, original_name: string} */
    public function inspect(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            throw new DocumentRejected('The upload did not complete.');
        }
        $path = (string) $file->getRealPath();
        $size = (int) filesize($path);
        $maxBytes = (int) config('infernix.documents.max_size_kb') * 1024;
        if ($size <= 0) {
            throw new DocumentRejected('The file is empty.');
        }
        if ($size > $maxBytes) {
            throw new DocumentRejected('The file exceeds the maximum size of '.intdiv($maxBytes, 1024 * 1024).' MB.');
        }

        $name = $this->safeName($file->getClientOriginalName());
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        /** @var array<string, list<string>> $allowed */
        $allowed = config('infernix.documents.allowed', []);
        if (! isset($allowed[$ext])) {
            throw new DocumentRejected("File type .{$ext} is not allowed. Allowed: ".implode(', ', array_keys($allowed)).'.');
        }

        $sniffed = (string) (new finfo(FILEINFO_MIME_TYPE))->file($path);
        if (! in_array($sniffed, $allowed[$ext], true)) {
            throw new DocumentRejected("The file content ({$sniffed}) does not match its .{$ext} extension.");
        }
        $mime = match ($ext) {
            'xlsx' => $this->assertOoxml($path, 'xl/', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
            'docx' => $this->assertOoxml($path, 'word/', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
            'csv' => $this->assertText($path),
            default => $sniffed,
        };

        return ['extension' => $ext, 'mime' => $mime, 'size' => $size, 'hash' => (string) hash_file('sha256', $path), 'original_name' => $name];
    }

    private function assertOoxml(string $path, string $partPrefix, string $mime): string
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new DocumentRejected('The file is not a valid Office document.');
        }
        $hasTypes = $zip->locateName('[Content_Types].xml') !== false;
        $hasPart = false;
        $hasMacros = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            $hasPart = $hasPart || str_starts_with($entry, $partPrefix);
            $hasMacros = $hasMacros || str_ends_with(strtolower($entry), 'vbaproject.bin');
        }
        $zip->close();
        if (! $hasTypes || ! $hasPart) {
            throw new DocumentRejected('The file is not a valid Office document.');
        }
        if ($hasMacros) {
            throw new DocumentRejected('Macro-enabled Office documents are not allowed.');
        }

        return $mime;
    }

    private function assertText(string $path): string
    {
        $head = (string) file_get_contents($path, false, null, 0, 8192);
        if (str_contains($head, "\0") || ! mb_check_encoding($head, 'UTF-8') && ! mb_check_encoding($head, 'ISO-8859-1')) {
            throw new DocumentRejected('The CSV file is not plain text.');
        }

        return 'text/csv';
    }

    /** Strip paths/control characters; keep a readable, safe file name. */
    private function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = (string) preg_replace('/[\x00-\x1F\x7F"<>:|?*]+/u', '', $name);
        $name = trim($name, " .\t");

        return $name === '' ? 'file' : mb_substr($name, 0, 200);
    }
}
