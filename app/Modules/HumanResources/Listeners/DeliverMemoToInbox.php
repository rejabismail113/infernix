<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Listeners;

use App\Modules\Commercial\Events\DocumentIssued;
use App\Modules\HumanResources\Models\StaffMemo;
use App\Modules\HumanResources\Models\StaffProfile;

/** Memos / appointment letters issued by M03 to a staff member land in their inbox (event data only). @see SRS M04, M03-06 */
final class DeliverMemoToInbox
{
    public function handle(DocumentIssued $event): void
    {
        if ($event->counterpartyType !== 'staff' || $event->counterpartyId === null || ! in_array($event->type, ['memo', 'appointment_letter', 'letter'], true)) {
            return;
        }
        if (! StaffProfile::query()->whereKey($event->counterpartyId)->exists()) {
            return;
        }
        StaffMemo::query()->firstOrCreate(['staff_profile_id' => $event->counterpartyId, 'commercial_document_id' => $event->documentId], ['subject' => (string) $event->subject, 'delivered_at' => now()]);
    }
}
