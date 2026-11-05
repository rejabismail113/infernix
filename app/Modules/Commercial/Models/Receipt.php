<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Official receipt: exists only together with its posted Dr Bank / Cr AR journal (journal_id NOT NULL, M03-04).
 *
 * @see SRS M03-04, UAT-04, WF-04
 *
 * @property int $id
 * @property int $company_id
 * @property string $receipt_no
 * @property Carbon $receipt_date
 * @property int $bank_account_id
 * @property Money $amount
 * @property Money $unapplied_amount
 * @property string $method
 * @property string|null $reference
 * @property int|null $client_id
 * @property string $payer_name
 * @property string|null $notes
 * @property int $journal_id
 * @property int|null $reversal_journal_id
 * @property string $status
 * @property int|null $pdf_document_id
 * @property int $created_by
 * @property string|null $void_reason
 * @property Carbon|null $voided_at
 * @property-read Collection<int, ReceiptAllocation> $allocations
 */
final class Receipt extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['receipt_date' => 'date', 'amount' => MoneyCast::class, 'unapplied_amount' => MoneyCast::class, 'voided_at' => 'datetime'];
    }

    public static function resourceName(): string
    {
        return 'receipts';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return HasMany<ReceiptAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(ReceiptAllocation::class);
    }
}
