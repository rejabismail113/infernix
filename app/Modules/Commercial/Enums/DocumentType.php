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
    case ClientPo = 'client_po';
    case VendorPo = 'vendor_po';
    case Memo = 'memo';
    case Letter = 'letter';
    case AppointmentLetter = 'appointment_letter';
    case Certificate = 'certificate';

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
            self::ClientPo => 'client_pos',
            self::VendorPo => 'vendor_pos',
            self::Memo => 'memos',
            self::Letter => 'letters',
            self::AppointmentLetter => 'appointment_letters',
            self::Certificate => 'certificates',
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
            self::ClientPo => 'Client PO / LOA',
            self::VendorPo => 'Vendor purchase order',
            self::Memo => 'Staff memo',
            self::Letter => 'Letter',
            self::AppointmentLetter => 'Appointment letter',
            self::Certificate => 'Training certificate',
        };
    }

    /** Built (lines, approval) in M03 rather than registered from another module's posting. */
    public function isBuilt(): bool
    {
        return in_array($this, [self::Quotation, self::Invoice, self::CreditNote, self::VendorPo], true);
    }

    /** Text documents generated from a template and issued directly (no lines, no approval) — M03-06. */
    public function isGenerated(): bool
    {
        return in_array($this, [self::Memo, self::Letter, self::AppointmentLetter, self::Certificate], true);
    }
}
