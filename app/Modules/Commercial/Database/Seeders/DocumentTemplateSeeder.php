<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Database\Seeders;

use App\Modules\Commercial\Models\DocumentTemplate;
use App\Modules\Commercial\Models\DocumentTemplateVersion;
use Illuminate\Database\Seeder;

/**
 * Template library v1 (Infernix branding). Wording is PLACEHOLDER pending owner / legal review (D-19).
 *
 * @see SRS M03-07, M03-08
 */
final class DocumentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $common = ['accent_color' => '#ea580c', 'footer_text' => 'Infernix Evolv Hub · [PLACEHOLDER — legal review] · This document is computer generated.'];
        $templates = [
            'quotation' => ['Quotation', ['title' => 'QUOTATION', 'show_bank_details' => false,
                'default_terms' => "1. This quotation is valid until the date shown.\n2. 50% deposit upon acceptance; balance upon completion. [PLACEHOLDER — legal review]\n3. Prices are in Malaysian Ringgit (MYR)."]],
            'invoice' => ['Invoice', ['title' => 'INVOICE', 'show_bank_details' => true,
                'default_terms' => 'Payment is due by the due date shown. Please quote the invoice number when paying. [PLACEHOLDER — legal review]']],
            'credit_note' => ['Credit note', ['title' => 'CREDIT NOTE', 'show_bank_details' => false, 'default_terms' => 'This credit note reduces the balance of the invoice referenced above.']],
            'receipt' => ['Official receipt', ['title' => 'OFFICIAL RECEIPT', 'show_bank_details' => false, 'default_terms' => 'Thank you for your payment.']],
            'payment_voucher' => ['Payment voucher', ['title' => 'PAYMENT VOUCHER', 'show_bank_details' => false, 'default_terms' => null]],
        ];
        foreach ($templates as $code => [$name, $settings]) {
            $template = DocumentTemplate::query()->firstOrCreate(['code' => $code], ['name' => $name, 'description' => 'Infernix standard layout', 'active' => true]);
            if (! DocumentTemplateVersion::query()->where('document_template_id', $template->id)->exists()) {
                DocumentTemplateVersion::query()->create([
                    'document_template_id' => $template->id, 'version' => 1, 'view_name' => 'commercial.templates.standard-v1',
                    'settings' => array_merge($common, $settings), 'notes' => 'PLACEHOLDER wording — owner/legal review (D-19)', 'effective_from' => '2026-01-01',
                ]);
            }
        }
    }
}
