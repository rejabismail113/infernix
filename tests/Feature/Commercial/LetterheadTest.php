<?php

declare(strict_types=1);

use App\Modules\Commercial\Services\TemplateRenderer;
use Illuminate\Support\Facades\Storage;

/** Letterhead logo (A-99): new documents carry the phoenix; older snapshots keep the logo they were issued with. */
beforeEach(fn () => Storage::fake(config('infernix.documents.disk')));

it('M03-07: a newly issued invoice snapshots the phoenix logo and renders it, with the company name beside it', function (): void {
    $invoice = issuedInvoice('500.00');
    $logo = $invoice->issuer_profile_snapshot['logo'];
    expect($logo['source'])->toBe('images/infernix-workspace-icon.png')
        ->and($logo['sha256'])->toBe(hash_file('sha256', public_path('images/infernix-workspace-icon.png')));

    $html = app(TemplateRenderer::class)->html($invoice);
    expect($html)->toContain('data:image/png;base64,')->toContain('Infernix Evolv Hub');
})->group('M03-07', 'M03-08');

it('M03-07: a document issued with the earlier SVG letterhead still renders that logo', function (): void {
    $invoice = issuedInvoice('500.00');
    $snapshot = $invoice->issuer_profile_snapshot;
    $snapshot['logo']['source'] = 'images/infernix-logo.svg';
    $invoice->forceFill(['issuer_profile_snapshot' => $snapshot]);

    expect(app(TemplateRenderer::class)->html($invoice))->toContain('data:image/svg+xml;base64,')->not->toContain('data:image/png;base64,');
})->group('M03-07');
