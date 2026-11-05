<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services;

use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Issues the ONLY kind of URL that can fetch file bytes: time-limited (5 min) and bound to the user.
 * S3/MinIO → presigned object URL with attachment disposition; local disks → signed app route that
 * re-checks the policy before streaming. Only called AFTER the policy check passed.
 *
 * @see SRS M06-02, UAT-10, NFR-02
 */
class DocumentUrlSigner
{
    public function temporaryUrl(DocumentVersion $version, User $user): string
    {
        $expires = now()->addMinutes((int) config('infernix.documents.signed_url_ttl_minutes', 5));
        $diskName = (string) config('infernix.documents.disk');
        $disk = Storage::disk($diskName);

        // Presigned object URLs only for S3-compatible object storage. Any other disk is served through the
        // signed app route, so a URL never contains a fetchable storage path.
        if (config("filesystems.disks.{$diskName}.driver") === 's3' && $disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($version->storage_key, $expires, [
                'ResponseContentType' => $version->mime_type,
                'ResponseContentDisposition' => 'attachment; filename="'.addcslashes($version->file_name, '"\\').'"',
                'ResponseCacheControl' => 'private, no-store',
            ]);
        }

        return URL::temporarySignedRoute('documents.stream', $expires, ['version' => $version->id, 'user' => $user->id]);
    }
}
