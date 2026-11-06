<?php

declare(strict_types=1);

namespace App\Kernel\Secrets;

/**
 * Scrubs secret-looking values from any text/array that leaves the system (logs, exports, notifications,
 * search payloads, audit). The dev/test sentinel (config infernix.secret_sentinel) is always scrubbed;
 * so are `key=value` pairs for password/secret/token style keys.
 *
 * @see SRS M13-02, M13-05, UAT-08
 */
final class SecretRedactor
{
    public const PLACEHOLDER = '[SECRET-REDACTED]';

    // The value stops at a backslash too, so scrubbing already-encoded JSON (`\"`) keeps it valid JSON.
    private const KEY_PATTERN = '/\b(password|passwd|pwd|secret|api[_-]?key|token|authorization|bearer)\b(\s*[:=]\s*)(["\']?)([^\s"\'&,;\\\\]+)/i';

    public function scrub(string $text): string
    {
        $sentinel = (string) config('infernix.secret_sentinel', '');
        if ($sentinel !== '' && str_contains($text, $sentinel)) {
            $text = str_replace($sentinel, self::PLACEHOLDER, $text);
        }
        $result = preg_replace_callback(self::KEY_PATTERN, fn (array $m): string => $m[1].$m[2].$m[3].self::PLACEHOLDER, $text);

        return $result ?? $text;
    }

    /**
     * @param  array<string, mixed>|null  $data
     * @return array<string, mixed>|null
     */
    public function scrubArray(?array $data): ?array
    {
        if ($data === null) {
            return null;
        }
        foreach ($data as $k => $v) {
            if (is_string($v)) {
                $data[$k] = $this->scrub($v);
            } elseif (is_array($v)) {
                $data[$k] = $this->scrubArray($v);
            }
        }

        return $data;
    }
}
