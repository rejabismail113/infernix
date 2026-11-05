<?php

declare(strict_types=1);

namespace App\Modules\DocumentCentre\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Kernel\Documents\DocumentLinkRegistry;
use App\Kernel\Documents\LinkTarget;
use App\Kernel\Identity\Models\User;
use App\Modules\DocumentCentre\Enums\Classification;
use App\Modules\DocumentCentre\Exceptions\DocumentRejected;
use App\Modules\DocumentCentre\Models\Document;
use App\Modules\DocumentCentre\Models\DocumentShare;
use App\Modules\DocumentCentre\Models\DocumentVersion;
use App\Modules\DocumentCentre\Models\EntityDocumentLink;
use App\Modules\DocumentCentre\Models\RetentionRule;
use App\Modules\DocumentCentre\Services\DocumentSearch;
use App\Modules\DocumentCentre\Services\DocumentService;
use App\Modules\DocumentCentre\Services\DocumentUrlSigner;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\GoneHttpException;

/**
 * Document Centre HTTP surface. No route returns a storage key or raw object URL; file bytes are only
 * reachable via download() → policy → audit → 5-minute signed URL (UAT-10).
 *
 * @see SRS M06-01 … M06-05, G-04, G-07, NFR-02
 */
final class DocumentController
{
    use AuthorizesRequests;

    public function __construct(
        private readonly DocumentService $documents,
        private readonly DocumentSearch $search,
        private readonly DocumentLinkRegistry $registry,
    ) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Document::class);
        /** @var User $user */
        $user = $request->user();
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'classification' => ['nullable', Rule::enum(Classification::class)],
            'entity_type' => ['nullable', 'string', 'max:48'],
            'entity_id' => ['nullable', 'integer'],
            'link_type' => ['nullable', 'string', 'max:32'],
            'tag' => ['nullable', 'string', 'max:64'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'include_archived' => ['nullable', 'boolean'],
        ]);

        $page = $this->search->query($user, $filters)
            ->with(['currentVersion', 'owner:id,name', 'links'])
            ->paginate(25)->withQueryString()
            ->through(fn (Document $d): array => $this->row($d));

        return Inertia::render('Documents/Index', [
            'documents' => $page,
            'filters' => $filters,
            'classifications' => array_map(fn (Classification $c) => ['value' => $c->value, 'label' => $c->label()], Classification::cases()),
            'entityTypes' => $this->entityTypes(),
            'retentionRules' => RetentionRule::query()->where('active', true)->orderBy('code')->get(['code', 'name']),
            'can' => ['create' => $user->can('create', Document::class), 'export' => $user->can('export', Document::class)],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'file' => ['required', 'file'],
            'title' => ['nullable', 'string', 'max:190'],
            'classification' => ['required', Rule::enum(Classification::class)],
            'retention_rule' => ['nullable', 'string', Rule::in([...RetentionRule::query()->pluck('code')->all(), 'none'])],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:40'],
            'entity_type' => ['nullable', 'string', Rule::in(array_keys($this->registry->all()))],
            'entity_id' => ['required_with:entity_type', 'nullable', 'integer'],
            'link_type' => ['required_with:entity_type', 'nullable', 'string', 'max:32'],
        ]);
        $link = $this->authorizedLink($user, $data);

        try {
            $stored = $this->documents->attach($request->file('file'), $user, [
                'title' => $data['title'] ?? '',
                'classification' => $data['classification'],
                'retention_rule' => $data['retention_rule'] ?? null,
                'tags' => $data['tags'] ?? [],
            ], $link);
        } catch (DocumentRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return redirect()->route('documents.show', $stored->documentId)->with('success', __('Document uploaded.'));
    }

    public function show(Request $request, Document $document): Response
    {
        $this->authorize('view', $document);
        /** @var User $user */
        $user = $request->user();
        $document->load(['versions.uploader:id,name', 'owner:id,name', 'links', 'shares.user:id,name', 'retentionRule']);

        return Inertia::render('Documents/Show', [
            'document' => $this->row($document) + [
                'tags' => $document->tags ?? [],
                'retention' => $document->retentionRule?->name,
                'retention_until' => $document->retention_until?->toDateString(),
                'legal_hold_reason' => $document->legal_hold_reason,
            ],
            'versions' => $document->versions->sortByDesc('version_no')->values()->map(fn (DocumentVersion $v): array => [
                'id' => $v->id, 'version_no' => $v->version_no, 'file_name' => $v->file_name, 'mime_type' => $v->mime_type,
                'size_bytes' => $v->size_bytes, 'content_hash' => $v->content_hash, 'scan_status' => $v->scan_status->value,
                'uploaded_by' => $v->uploader->name, 'created_at' => $v->created_at->toIso8601String(),
            ]),
            'links' => $document->links->map(fn (EntityDocumentLink $l): array => [
                'id' => $l->id, 'entity_type' => $l->entity_type, 'entity_id' => $l->entity_id, 'link_type' => $l->link_type,
                'required_evidence' => $l->required_evidence, 'label' => $this->linkLabel($l, $user),
            ]),
            'shares' => $user->can('update', $document) ? $document->shares->map(fn (DocumentShare $s): array => [
                'id' => $s->id, 'user' => $s->user->name, 'expires_at' => $s->expires_at?->toIso8601String(), 'active' => $s->isActive(), 'reason' => $s->reason,
            ]) : [],
            'entityTypes' => $this->entityTypes(),
            'users' => $user->can('update', $document) ? User::query()->whereNull('deactivated_at')->where('id', '!=', $document->owner_id)->orderBy('name')->get(['id', 'name']) : [],
            'can' => [
                'edit' => $user->can('update', $document),
                'delete' => $user->can('delete', $document),
                'legalHold' => $user->can('approve', $document),
            ],
        ]);
    }

    public function storeVersion(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $request->validate(['file' => ['required', 'file']]);
        /** @var User $user */
        $user = $request->user();
        try {
            $this->documents->newVersion($document, $request->file('file'), $user);
        } catch (DocumentRejected $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        return back()->with('success', __('New version uploaded.'));
    }

    /** Policy → audit → 5-minute signed URL. Unauthorized callers get 403 and NO URL is ever generated. */
    public function download(Request $request, Document $document, DocumentUrlSigner $signer): RedirectResponse
    {
        $this->authorize('download', $document);
        /** @var User $user */
        $user = $request->user();
        $versionNo = $request->integer('version') ?: $document->current_version_no;
        /** @var DocumentVersion|null $version */
        $version = $document->versions()->where('version_no', $versionNo)->first();
        if ($version === null) {
            abort(404);
        }
        if (! $version->scan_status->allowsStorage()) {
            throw new AccessDeniedHttpException('This file version is quarantined.');
        }
        Audit::log('download', $document, null, null, ['version_no' => $version->version_no, 'content_hash' => $version->content_hash], null, $user);

        return redirect()->away($signer->temporaryUrl($version, $user));
    }

    /**
     * Target of the signed URL on local disks. The signature (expiry + params) is validated by the
     * `signed` middleware; the bound user must be the current user and the policy is checked AGAIN.
     */
    public function stream(Request $request, DocumentVersion $version): StreamedResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ((int) $request->query('user') !== $user->id) {
            throw new AccessDeniedHttpException('This download link was issued to another user.');
        }
        $document = $version->document;
        if ($document->isHardDeleted()) {
            throw new GoneHttpException;
        }
        $this->authorize('download', $document);

        $disk = $this->documents->disk();
        $contents = $disk->get($version->storage_key);
        if ($contents === null || hash('sha256', $contents) !== $version->content_hash) {
            Audit::log('integrity_failure', $document, null, null, ['version_no' => $version->version_no], null, $user);
            abort(409, 'Stored file failed its integrity check.');
        }

        return response()->streamDownload(fn () => print ($contents), $version->file_name, [
            'Content-Type' => $version->mime_type,
            'Content-Length' => (string) $version->size_bytes,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }

    public function link(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate([
            'entity_type' => ['required', 'string', Rule::in(array_keys($this->registry->all()))],
            'entity_id' => ['required', 'integer'],
            'link_type' => ['required', 'string', 'max:32'],
            'required_evidence' => ['nullable', 'boolean'],
        ]);
        $link = $this->authorizedLink($user, $data);
        try {
            $this->documents->link($document->id, $link ?? throw new DocumentRejected('Link target required.'), $user, (bool) ($data['required_evidence'] ?? false));
        } catch (DocumentRejected $e) {
            throw ValidationException::withMessages(['entity_id' => $e->getMessage()]);
        }

        return back()->with('success', __('Document linked.'));
    }

    public function share(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        $data = $request->validate([
            'user_id' => ['required', 'exists:users,id'],
            'expires_at' => ['nullable', 'date', 'after:now'],
            'reason' => ['required', 'string', 'max:500'],
        ]);
        /** @var User $user */
        $user = $request->user();
        try {
            $this->documents->share($document, User::query()->findOrFail($data['user_id']), isset($data['expires_at']) ? Carbon::parse($data['expires_at']) : null, $data['reason'], $user);
        } catch (DocumentRejected $e) {
            throw ValidationException::withMessages(['user_id' => $e->getMessage()]);
        }

        return back()->with('success', __('Document shared.'));
    }

    public function unshare(Request $request, Document $document, DocumentShare $share): RedirectResponse
    {
        $this->authorize('update', $document);
        /** @var User $user */
        $user = $request->user();
        $this->documents->unshare($document, $share, $user);

        return back()->with('success', __('Share removed.'));
    }

    public function archive(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('update', $document);
        /** @var User $user */
        $user = $request->user();
        $request->boolean('restore')
            ? $this->documents->unarchive($document, $user, $request->string('reason')->toString() ?: null)
            : $this->documents->archive($document, $user, $request->string('reason')->toString() ?: null);

        return back()->with('success', $request->boolean('restore') ? __('Document restored.') : __('Document archived.'));
    }

    public function legalHold(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('approve', $document);
        $data = $request->validate(['hold' => ['required', 'boolean'], 'reason' => ['required', 'string', 'max:500']]);
        /** @var User $user */
        $user = $request->user();
        $this->documents->setLegalHold($document, (bool) $data['hold'], $data['reason'], $user);

        return back()->with('success', $data['hold'] ? __('Legal hold applied.') : __('Legal hold released.'));
    }

    public function destroy(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('delete', $document);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        /** @var User $user */
        $user = $request->user();
        try {
            $this->documents->hardDelete($document, $user, $data['reason']);
        } catch (DocumentRejected $e) {
            throw ValidationException::withMessages(['reason' => $e->getMessage()]);
        }

        return redirect()->route('documents.index')->with('success', __('Document permanently deleted.'));
    }

    /**
     * Linking requires that the user may UPDATE the target record (attaching evidence changes it).
     *
     * @param  array<string, mixed>  $data
     */
    private function authorizedLink(User $user, array $data): ?LinkTarget
    {
        if (empty($data['entity_type'])) {
            return null;
        }
        $entity = $this->registry->find((string) $data['entity_type'], (int) $data['entity_id']);
        if ($entity === null) {
            throw ValidationException::withMessages(['entity_id' => __('The record to link to does not exist.')]);
        }
        if (! Gate::forUser($user)->allows('update', $entity)) {
            throw new AccessDeniedHttpException('You may not attach documents to this record.');
        }

        return new LinkTarget((string) $data['entity_type'], (int) $data['entity_id'], (string) $data['link_type']);
    }

    /** @return list<array{type: string, label: string, link_types: list<string>}> */
    private function entityTypes(): array
    {
        $out = [];
        foreach ($this->registry->all() as $type) {
            $out[] = ['type' => $type->type, 'label' => $type->label, 'link_types' => $this->registry->linkTypesFor($type->type)];
        }

        return $out;
    }

    private function linkLabel(EntityDocumentLink $l, User $user): string
    {
        if (! $this->registry->has($l->entity_type)) {
            return $l->entity_type.' #'.$l->entity_id;
        }
        $type = $this->registry->get($l->entity_type);
        $entity = $this->registry->find($l->entity_type, $l->entity_id);

        // Never leak the label of a linked record the viewer cannot see.
        return $entity !== null && Gate::forUser($user)->allows('view', $entity)
            ? $type->label.': '.$type->label($entity)
            : $type->label.' #'.$l->entity_id;
    }

    /** @return array<string, mixed> */
    private function row(Document $d): array
    {
        $v = $d->currentVersion;

        return [
            'id' => $d->id,
            'title' => $d->title,
            'classification' => $d->classification->value,
            'owner' => $d->owner->name,
            'version' => $d->current_version_no,
            'file_name' => $v?->file_name,
            'mime_type' => $v?->mime_type,
            'size_bytes' => $v?->size_bytes,
            'links' => $d->links->map(fn (EntityDocumentLink $l): string => $l->entity_type.':'.$l->link_type)->unique()->values()->all(),
            'legal_hold' => $d->legal_hold,
            'frozen' => $d->frozen,
            'archived' => $d->isArchived(),
            'uploaded_at' => $d->uploaded_at->toIso8601String(),
        ];
    }
}
