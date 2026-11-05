<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Creates the PRIVATE documents bucket on the S3-compatible store if it does not exist, and makes sure no
 * public bucket policy is attached (CLAUDE.md rule 3: nothing is ever publicly readable). Works with any
 * S3 API (RustFS in dev, a managed bucket in production), so the stack needs no vendor CLI image.
 *
 * @see SRS M06-02, NFR-02, G-07
 */
final class EnsureDocumentsBucket extends Command
{
    protected $signature = 'storage:ensure-bucket {--wait=60 : Seconds to wait for the object store to come up}';

    protected $description = 'Create the private documents bucket on the S3-compatible object store (idempotent).';

    public function handle(): int
    {
        $diskName = (string) config('infernix.documents.disk');
        $disk = Storage::disk($diskName);
        if (! $disk instanceof AwsS3V3Adapter) {
            $this->info("Disk [{$diskName}] is not S3-backed; nothing to do.");

            return self::SUCCESS;
        }
        /** @var S3Client $client */
        $client = $disk->getClient();
        $bucket = (string) config("filesystems.disks.{$diskName}.bucket");
        $deadline = time() + max(0, (int) $this->option('wait'));

        while (true) {
            try {
                if (! $client->doesBucketExistV2($bucket, false)) {
                    $client->createBucket(['Bucket' => $bucket]);
                    $this->info("Created private bucket [{$bucket}].");
                } else {
                    $this->info("Bucket [{$bucket}] already exists.");
                }
                break;
            } catch (Throwable $e) {
                if (time() >= $deadline) {
                    $this->error("Object store not reachable for bucket [{$bucket}]: {$e->getMessage()}");

                    return self::FAILURE;
                }
                $this->line('Waiting for the object store…');
                sleep(2);
            }
        }

        try {
            $client->deleteBucketPolicy(['Bucket' => $bucket]);
        } catch (S3Exception) {
            // No policy attached (the normal case) or the store does not support policies: bucket stays private.
        }

        return self::SUCCESS;
    }
}
