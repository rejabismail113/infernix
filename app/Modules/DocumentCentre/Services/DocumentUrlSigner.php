<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Services;

use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Models\DocumentVersion;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Issues the ONLY kind of URL that can fetch file bytes: time-limited (5 min) and bound to the user.
 * Default: signed app route that re-checks the policy before streaming (any disk). With DOCUMENT_DELIVERY=presigned
 * and an S3-compatible disk: presigned object URL with attachment disposition — only when browsers can reach the
 * object store's host. Only called AFTER the policy check passed.
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

        // Presigned object URLs only when explicitly enabled for S3-compatible storage that browsers can reach.
        // Otherwise the signed app route serves the bytes, so a URL never contains a fetchable storage path.
        if (config('infernix.documents.delivery') === 'presigned'
            && config("filesystems.disks.{$diskName}.driver") === 's3' && $disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($version->storage_key, $expires, [
                'ResponseContentType' => $version->mime_type,
                'ResponseContentDisposition' => 'attachment; filename="'.addcslashes($version->file_name, '"\\').'"',
                'ResponseCacheControl' => 'private, no-store',
            ]);
        }

        return URL::temporarySignedRoute('documents.stream', $expires, ['version' => $version->id, 'user' => $user->id]);
    }
}
