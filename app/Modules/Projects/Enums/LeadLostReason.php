<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Why a lead was lost (mirrored by leads_lost_reason_check). `Other` needs an explanation.
 *
 * @see SRS M02-02, WF-04
 */
enum LeadLostReason: string
{
    case PriceBudget = 'price_budget';
    case Competitor = 'competitor';
    case ClientCancelled = 'client_cancelled';
    case NoResponse = 'no_response';
    case Postponed = 'postponed';
    case ScopeNotSuitable = 'scope_not_suitable';
    case InternalResource = 'internal_resource';
    case TenderUnsuccessful = 'tender_unsuccessful';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PriceBudget => 'Price / Budget Constraints',
            self::Competitor => 'Competitor Selected',
            self::ClientCancelled => 'Client Cancelled',
            self::NoResponse => 'No Response from Client',
            self::Postponed => 'Project Postponed',
            self::ScopeNotSuitable => 'Scope Not Suitable',
            self::InternalResource => 'Internal Resource Constraints',
            self::TenderUnsuccessful => 'Tender Unsuccessful',
            self::Other => 'Others',
        };
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (self $r): array => ['value' => $r->value, 'label' => $r->label()], self::cases());
    }
}
