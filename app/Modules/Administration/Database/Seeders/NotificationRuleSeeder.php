<?php

declare(strict_types=1);

namespace App\Modules\Administration\Database\Seeders;

use App\Modules\Administration\Models\NotificationRule;
use Illuminate\Database\Seeder;

/** G-05 groundwork; lead days are PLACEHOLDER (docs/DECISIONS.md D-22). */
final class NotificationRuleSeeder extends Seeder
{
    public function run(): void
    {
        $rules = [
            ['ApprovalPending', ['OWN', 'FIN', 'APP'], 'both', 0],
            ['InvoiceOverdue', ['OWN', 'FIN'], 'both', 1],
            ['InvoiceOverdue', ['OWN', 'FIN'], 'email', 14],
            ['InvoiceOverdue', ['OWN', 'FIN'], 'email', 30],
            ['CertificationExpiring', ['HR', 'OWN'], 'both', 90],
            ['CertificationExpiring', ['HR', 'OWN'], 'both', 60],
            ['CertificationExpiring', ['HR', 'OWN'], 'both', 30],
            ['SubscriptionRenewalDue', ['FIN', 'OWN'], 'both', 90],
            ['SubscriptionRenewalDue', ['FIN', 'OWN'], 'both', 60],
            ['SubscriptionRenewalDue', ['FIN', 'OWN'], 'both', 30],
            ['CredentialRotationDue', ['OWN'], 'in_app', 14],
            ['BackupFailed', ['OWN'], 'both', 0],
        ];
        foreach ($rules as [$event, $roles, $channel, $lead]) {
            NotificationRule::withoutEvents(fn () => NotificationRule::query()->firstOrCreate(['event' => $event, 'lead_days' => $lead], [
                'target_roles' => $roles, 'channel' => $channel, 'active' => true, 'notes' => 'PLACEHOLDER lead days',
            ]));
        }
    }
}
