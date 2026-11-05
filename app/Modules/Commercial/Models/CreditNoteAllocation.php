<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Settlement of an invoice. Never deleted; voided with its parent. The invoice_allocation_ceiling trigger
 * keeps receipts + credits ≤ invoice total.
 *
 * @see SRS M03-03, M03-04, UAT-04
 *
 * @property int $id
 * @property int $credit_note_id
 * @property int $invoice_id
 * @property Money $amount
 * @property Carbon|null $voided_at
 */
final class CreditNoteAllocation extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'credit_note_allocations';

    /** @var list<string> */
    protected $fillable = ['credit_note_id', 'invoice_id', 'amount', 'voided_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => MoneyCast::class, 'voided_at' => 'datetime'];
    }
}
