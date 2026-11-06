<?php

declare(strict_types=1);

namespace App\Kernel\Secrets;

use Illuminate\Database\Eloquent\Model;

/**
 * Global last line of defence for "no secret in any column" (engineering rule 7): on every Eloquent save, each
 * dirty string attribute (free text, JSON) is passed through the SecretRedactor, so a password or API key pasted
 * into a notes / description / purpose field is stored as [SECRET-REDACTED]. Encrypted casts are already
 * ciphertext at this point and pass through unchanged; password hashes never match the key=value pattern.
 * Forms that must refuse secrets outright (credentials metadata, importers) still do so before saving.
 *
 * @see SRS M13-02, M13-05, UAT-08
 */
final class ScrubSecretsOnSave
{
    public function __construct(private readonly SecretRedactor $redactor) {}

    /** @param  array<int, mixed>  $payload */
    public function handle(string $event, array $payload): void
    {
        $model = $payload[0] ?? null;
        if (! $model instanceof Model) {
            return;
        }
        $raw = $model->getAttributes();
        foreach (array_keys($model->getDirty()) as $key) {
            $value = $raw[$key] ?? null;
            if (! is_string($value) || $value === '') {
                continue;
            }
            $clean = $this->redactor->scrub($value);
            if ($clean !== $value) {
                // Raw write: avoids re-running casts / mutators on an already-cast value.
                $model->setRawAttributes([$key => $clean] + $model->getAttributes());
            }
        }
    }
}
