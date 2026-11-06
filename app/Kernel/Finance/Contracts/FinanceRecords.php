<?php

declare(strict_types=1);

namespace App\Kernel\Finance\Contracts;

use App\Kernel\Identity\Models\User;

/**
 * Read + LINK access to booked finance records (M01) for operational modules (M05, M07, M08, M09, M10).
 * Operational records point at existing transactions / bills / claims / payments; nothing here creates money,
 * a journal or an expense (CLAUDE.md rule 12). Amounts are decimal strings.
 *
 * Record snapshot shape: array{type: string, id: int, number: string, date: string, amount: string,
 *   status: string, party: string|null, description: string|null, url: string}
 *
 * @see SRS M05-01, M07-02, M08-02, M09-04, M10-02, M10-05, WF-02, WF-06, UAT-03, UAT-12
 */
interface FinanceRecords
{
    public const SOURCE_TYPES = ['transaction', 'bill', 'claim', 'payment'];

    public const LINE_TYPES = ['transaction_line', 'claim_line', 'bill_line'];

    /** @return array{type: string, id: int, number: string, date: string, amount: string, status: string, party: string|null, description: string|null, url: string}|null */
    public function snapshot(string $type, int $id): ?array;

    /**
     * Booked records that may be linked: posted expenses/income, approved bills, approved claims, paid payments.
     *
     * @return list<array{type: string, id: int, number: string, date: string, amount: string, status: string, party: string|null, description: string|null, url: string}>
     */
    public function search(string $type, string $q, int $limit = 20): array;

    public function link(string $entityType, int $entityId, string $sourceType, int $sourceId, string $purpose, User $actor, ?string $note = null): void;

    public function unlink(string $entityType, int $entityId, string $sourceType, int $sourceId, User $actor): void;

    /** @return list<array{type: string, id: int, number: string, date: string, amount: string, status: string, party: string|null, description: string|null, url: string, purpose: string, note: string|null}> */
    public function linked(string $entityType, int $entityId): array;

    /**
     * Sum of linked record amounts per entity (each finance record counted once per entity).
     *
     * @param  list<int>  $entityIds
     * @return array<int, string>
     */
    public function linkedTotals(string $entityType, array $entityIds, ?string $purpose = null): array;

    /**
     * Booked cost lines tagged to an activity (claim lines, expense lines, bill lines) — M07 budget vs actual.
     *
     * @return list<array{line_type: string, line_id: int, source_type: string, source_id: int, number: string, date: string, description: string, kind: string, amount: string, project_id: int|null, url: string}>
     */
    public function activityLines(int $activityId): array;

    /**
     * Booked cost lines that may be tagged to an activity (search by number/description).
     *
     * @return list<array{line_type: string, line_id: int, number: string, date: string, description: string, amount: string, activity_id: int|null}>
     */
    public function taggableLines(string $q, int $limit = 20): array;

    /** Tag (or untag with null) a cost line to an activity. A line belongs to at most one activity (no double count). */
    public function tagActivity(string $lineType, int $lineId, ?int $activityId, User $actor): void;

    /**
     * Bills recorded against a vendor PO, with their payments (M10-02 audit chain).
     *
     * @return list<array{id: int, number: string, vendor_invoice_no: string, date: string, amount: string, paid: string, status: string, url: string, payments: list<array{id: int, number: string, date: string, amount: string, url: string}>}>
     */
    public function billsForVendorPo(int $vendorPoId): array;

    /** @return list<array{vendor_id: int, vendor: string, bills: int, billed: string, paid: string, outstanding: string}> */
    public function vendorSpending(string $from, string $to): array;

    /** @return array{id: int, journal_no: string}|null the journal posted for a source (e.g. asset_disposal) */
    public function journalFor(string $sourceType, int $sourceId): ?array;
}
