<?php

declare(strict_types=1);

namespace App\Modules\Finance\Models;

use App\Kernel\Audit\Auditable;
use App\Kernel\Identity\Contracts\PermissionResource;
use App\Kernel\Identity\Models\User;
use App\Kernel\Money\MoneyCast;
use App\Modules\Finance\Enums\BillStatus;
use App\Modules\Finance\Enums\TaxTreatment;
use App\Modules\Procurement\Models\Vendor;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Vendor bill (payable). Unique (vendor, vendor_invoice_no) prevents the same invoice being recorded —
 * and paid — twice (M10-03).
 *
 * @see SRS M01-05, M10-03
 *
 * @property int $id
 * @property int $company_id
 * @property string $bill_no
 * @property int $vendor_id
 * @property string $vendor_invoice_no
 * @property Carbon $bill_date
 * @property Carbon $due_date
 * @property Money $total_amount
 * @property Money $paid_amount
 * @property BillStatus $status
 * @property int $current_level
 * @property TaxTreatment $tax_treatment
 * @property string|null $description
 * @property int|null $journal_id
 * @property int|null $vendor_po_id
 * @property int|null $goods_receipt_id
 * @property int $created_by
 * @property-read Vendor $vendor
 * @property-read Collection<int, BillLine> $lines
 */
final class Bill extends Model implements PermissionResource
{
    use Auditable;

    /** @var list<string> */
    protected $fillable = ['company_id', 'bill_no', 'vendor_id', 'vendor_invoice_no', 'bill_date', 'due_date', 'description', 'created_by', 'vendor_po_id', 'goods_receipt_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'bill_date' => 'date', 'due_date' => 'date', 'total_amount' => MoneyCast::class, 'paid_amount' => MoneyCast::class,
            'status' => BillStatus::class, 'tax_treatment' => TaxTreatment::class, 'current_level' => 'integer', 'approved_at' => 'datetime',
        ];
    }

    public static function resourceName(): string
    {
        return 'bills';
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->created_by === $user->id;
    }

    public function isAssignedTo(User $user): bool
    {
        return false;
    }

    /** @return BelongsTo<Vendor, $this> */
    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class);
    }

    /** @return HasMany<BillLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BillLine::class)->orderBy('line_no');
    }

    public function outstanding(): Money
    {
        return $this->total_amount->minus($this->paid_amount);
    }
}
