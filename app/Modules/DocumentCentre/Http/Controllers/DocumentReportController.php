<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Kernel\Reports\CsvExport;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Reports\DocumentReports;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** @see SRS M06 outputs: Document inventory; Recent changes; Missing required evidence. M11-05 */
final class DocumentReportController
{
    use AuthorizesRequests;

    public function __construct(private readonly DocumentReports $reports) {}

    public function inventory(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Documents/Reports/Inventory', [
            'rows' => $this->reports->inventory($user),
            'can' => ['export' => $user->can('export', Document::class)],
        ]);
    }

    public function inventoryExport(Request $request, CsvExport $csv): StreamedResponse
    {
        $this->authorize('export', Document::class);
        /** @var User $user */
        $user = $request->user();
        $rows = array_map(fn (array $r): array => array_map(fn ($v) => is_bool($v) ? ($v ? 'yes' : 'no') : $v, array_values($r)), $this->reports->inventory($user));

        return $csv->stream('document_inventory', ['id', 'title', 'classification', 'owner', 'current_version', 'versions', 'links', 'file_name', 'mime_type', 'size_bytes', 'content_hash', 'retention', 'retention_until', 'legal_hold', 'frozen', 'archived_at', 'uploaded_at'], $rows);
    }

    public function recentChanges(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);
        $days = max(1, min(365, $request->integer('days', 30)));
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Documents/Reports/RecentChanges', ['rows' => $this->reports->recentChanges($user, $days), 'days' => $days]);
    }

    public function missingEvidence(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Documents/Reports/MissingEvidence', ['rows' => $this->reports->missingEvidence($user)]);
    }
}
