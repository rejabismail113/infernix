<?php

declare(strict_types=1);

namespace App\Modules\Finance\Http\Controllers;

use App\Kernel\Finance\Contracts\FinanceRecords;
use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Picker search over BOOKED finance records, used by operational modules to LINK (never copy) a cost or
 * receipt (assets, renewals, subscriptions, disposals). Requires view rights on that finance register.
 *
 * @see SRS M05-03, M09-04, M10-05, NFR-02
 */
final class FinanceRecordController
{
    private const RESOURCE = ['transaction' => 'transactions', 'bill' => 'bills', 'claim' => 'claims', 'payment' => 'payments'];

    public function search(Request $request, FinanceRecords $records, PermissionService $permissions): JsonResponse
    {
        $data = $request->validate(['type' => ['required', 'in:transaction,bill,claim,payment'], 'q' => ['nullable', 'string', 'max:80']]);
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::View, self::RESOURCE[$data['type']]), 403);

        return response()->json(['records' => $records->search($data['type'], (string) ($data['q'] ?? ''), 20)]);
    }
}
