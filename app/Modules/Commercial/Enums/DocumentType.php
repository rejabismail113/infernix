<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Enums;

/** @see SRS M03 (document_type) */
enum DocumentType: string
{
    case Quotation = 'quotation';
    case Invoice = 'invoice';
    case CreditNote = 'credit_note';
    case Receipt = 'receipt';
    case PaymentVoucher = 'payment_voucher';

    /** numbering_sequences.document_type */
    public function sequence(): string
    {
        return strtoupper($this->value);
    }

    /** permission matrix resource */
    public function resource(): string
    {
        return match ($this) {
            self::Quotation => 'quotations',
            self::Invoice => 'invoices',
            self::CreditNote => 'credit_notes',
            self::Receipt => 'receipts',
            self::PaymentVoucher => 'payments',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Quotation => 'Quotation',
            self::Invoice => 'Invoice',
            self::CreditNote => 'Credit note',
            self::Receipt => 'Official receipt',
            self::PaymentVoucher => 'Payment voucher',
        };
    }

    /** Built (lines, approval) in M03 rather than registered from another module's posting. */
    public function isBuilt(): bool
    {
        return in_array($this, [self::Quotation, self::Invoice, self::CreditNote], true);
    }
}
