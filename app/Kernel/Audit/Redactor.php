<?php

declare(strict_types=1);

namespace App\Kernel\Audit;

/**
 * Removes PII/secret values from audit payloads (recursively) and reports which keys were redacted.
 *
 * @see SRS NFR-03, NFR-07
 */
final class Redactor
{
    /** @var list<string> */
    private array $keys;

    private string $placeholder;

    /** @param  list<string>  $extraKeys */
    public function __construct(array $extraKeys = [])
    {
        /** @var list<string> $configured */
        $configured = config('audit.redact_keys', []);
        $this->keys = array_values(array_unique(array_map('strtolower', [...$configured, ...$extraKeys])));
        $this->placeholder = (string) config('audit.placeholder', '[REDACTED]');
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array{0: array<string, mixed>|null, 1: list<string>}
     */
    public function redact(?array $payload): array
    {
        if ($payload === null) {
            return [null, []];
        }
        $redacted = [];
        $clean = $this->walk($payload, '', $redacted);

        return [$clean, array_values(array_unique($redacted))];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $redacted
     * @return array<string, mixed>
     */
    private function walk(array $data, string $prefix, array &$redacted): array
    {
        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix.'.'.$key;
            if (is_string($key) && in_array(strtolower($key), $this->keys, true)) {
                $data[$key] = $this->placeholder;
                $redacted[] = $path;
            } elseif (is_array($value)) {
                $data[$key] = $this->walk($value, $path, $redacted);
            }
        }

        return $data;
    }
}
