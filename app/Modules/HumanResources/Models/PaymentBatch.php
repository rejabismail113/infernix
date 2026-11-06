<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Models;

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
 * Part-time / contractor payment batch: draft → pending approval (matrix + maker-checker) → approved (accrual
 * posted) → paid (payout posted + payment voucher / payroll handoff CSV).
 *
 * @see SRS M04-03, M04-04, M04-05, WF-05
 *
 * @property int $id
 * @property int $company_id
 * @property string $batch_no
 * @property Carbon $period_end
 * @property string $status
 * @property string $payout_method
 * @property Money $total_amount
 * @property int $current_level
 * @property int $created_by
 * @property int|null $approved_by
 * @property Carbon|null $approved_at
 * @property int|null $accrual_journal_id
 * @property int|null $payout_journal_id
 * @property int|null $bank_account_id
 * @property Carbon|null $paid_on
 * @property string|null $voucher_no
 * @property int|null $voucher_document_id
 * @property int|null $handoff_document_id
 * @property-read Collection<int, PaymentBatchLine> $lines
 */
final class PaymentBatch extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['period_end' => 'date', 'total_amount' => MoneyCast::class, 'approved_at' => 'datetime', 'paid_on' => 'date', 'current_level' => 'integer'];
    }

    public static function resourceName(): string
    {
        return 'payment_batches';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return $this->status === 'pending_approval';
    }

    /** @return HasMany<PaymentBatchLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PaymentBatchLine::class);
    }
}
