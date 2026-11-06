<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Modules\Commercial\Enums\DocumentStatus;
use App\Modules\Commercial\Models\Certificate;
use Illuminate\Http\Response;

/**
 * PUBLIC certificate verification (the URL in the certificate's QR code). Deliberately reveals only whether the
 * serial number is valid, the course and the issue date — no participant name, organisation or email (PDPA).
 * Unguessable 40-char token; throttled; no session, no Inertia shared props.
 *
 * @see SRS M03-06, NFR-02, NFR-07
 */
final class CertificateVerifyController
{
    public function __invoke(string $token): Response
    {
        $cert = preg_match('/^[a-z0-9]{40}$/', $token) === 1 ? Certificate::query()->where('verification_token', $token)->first() : null;

        return response()->view('public.certificate-verify', [
            'found' => $cert !== null,
            'valid' => $cert !== null && $cert->status === DocumentStatus::Issued,
            'serial' => $cert?->document_no,
            'course' => $cert?->subject,
            'issued' => $cert?->issue_date?->format('j F Y'),
            'issuer' => $cert?->issuer_profile_snapshot['name'] ?? config('infernix.company.name'),
        ], $cert === null ? 404 : 200)->header('X-Robots-Tag', 'noindex')->header('Cache-Control', 'no-store');
    }
}
