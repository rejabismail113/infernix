<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @see SRS M10-02
 *
 * @property int $id
 * @property int $goods_receipt_id
 * @property int|null $po_line_id
 * @property string $description
 * @property string $quantity_ordered
 * @property string $quantity_received
 * @property string|null $condition
 * @property-read GoodsReceipt $receipt
 */
final class GoodsReceiptLine extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return BelongsTo<GoodsReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class, 'goods_receipt_id');
    }
}
