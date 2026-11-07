<?php

declare(strict_types=1);

namespace App\Modules\Projects\Enums;

/**
 * Opportunity type on a lead (mirrored by leads_engagement_check). `Other` needs a description.
 *
 * @see SRS M02-02
 */
enum LeadType: string
{
    case Project = 'project';
    case Training = 'training';
    case Consulting = 'consulting';
    case Retainer = 'retainer';
    case Support = 'support';
    case Licensing = 'licensing';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Project => 'Project',
            self::Training => 'Training',
            self::Consulting => 'Consulting',
            self::Retainer => 'Retainer',
            self::Support => 'Support & Maintenance',
            self::Licensing => 'Product / Licensing',
            self::Other => 'Others',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Project => 'System development, dashboard, implementation',
            self::Training => 'Corporate training, workshop, technical courses',
            self::Consulting => 'Advisory, assessment, technical consultation',
            self::Retainer => 'Recurring professional services',
            self::Support => 'Technical support, bug fixing, maintenance',
            self::Licensing => 'Software, subscription or licence resale',
            self::Other => 'Other business opportunities',
        };
    }

    /** @return list<array{value: string, label: string, description: string}> */
    public static function options(): array
    {
        return array_map(fn (self $t): array => ['value' => $t->value, 'label' => $t->label(), 'description' => $t->description()], self::cases());
    }
}
