<?php

declare(strict_types=1);

namespace App\Modules\HumanResources\Http\Controllers;

use App\Kernel\Identity\Models\User;
use App\Modules\HumanResources\Models\StaffMemo;
use App\Modules\HumanResources\Models\StaffProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** Memo inbox: a staff member's own memos / letters only (M04-02). PDFs download via the signed Document Centre route. */
final class MemoController
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $me = StaffProfile::query()->where('user_id', $user->id)->first();
        $memos = $me === null ? collect() : StaffMemo::query()->where('staff_profile_id', $me->id)->orderByDesc('delivered_at')->get();
        $docs = DB::table('commercial_documents')->whereIn('id', $memos->pluck('commercial_document_id'))->get(['id', 'document_no', 'type', 'pdf_document_id', 'status'])->keyBy('id');

        return Inertia::render('HR/Memos/Index', [
            'memos' => $memos->map(fn (StaffMemo $m): array => ['id' => $m->id, 'subject' => $m->subject, 'delivered_at' => $m->delivered_at->toIso8601String(), 'read' => $m->read_at !== null,
                'number' => $docs[$m->commercial_document_id]->document_no ?? null, 'type' => $docs[$m->commercial_document_id]->type ?? null, 'status' => $docs[$m->commercial_document_id]->status ?? null,
                'pdf' => isset($docs[$m->commercial_document_id]) && $docs[$m->commercial_document_id]->pdf_document_id ? route('documents.download', ['document' => $docs[$m->commercial_document_id]->pdf_document_id]) : null])->values(),
            'hasProfile' => $me !== null,
        ]);
    }

    public function read(Request $request, StaffMemo $memo): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless(StaffProfile::query()->whereKey($memo->staff_profile_id)->where('user_id', $user->id)->exists(), 403);
        $memo->forceFill(['read_at' => $memo->read_at ?? now()])->save();

        return back();
    }
}
