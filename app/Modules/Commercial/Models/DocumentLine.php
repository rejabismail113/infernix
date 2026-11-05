<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Models;

use App\Kernel\Money\MoneyCast;
use Brick\Money\Money;
use Illuminate\Database\Eloquent\Model;

/**
 * Normalised line of a commercial document. Editable only while the document is a draft (DB trigger).
 *
 * @see SRS M03-01, M03-03
 *
 * @property int $id
 * @property int $commercial_document_id
 * @property int $line_no
 * @property string $description
 * @property string $quantity
 * @property string|null $unit
 * @property Money $unit_price
 * @property Money $discount
 * @property int|null $tax_code_id
 * @property string $tax_rate
 * @property Money $net_amount
 * @property Money $tax_amount
 * @property Money $line_total
 * @property int|null $revenue_account_id
 * @property int|null $milestone_id
 * @property int|null $source_line_id
 */
final class DocumentLine extends Model
{
    /** @var list<string> */
    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price' => MoneyCast::class, 'discount' => MoneyCast::class, 'net_amount' => MoneyCast::class,
            'tax_amount' => MoneyCast::class, 'line_total' => MoneyCast::class, 'line_no' => 'integer',
        ];
    }
}
