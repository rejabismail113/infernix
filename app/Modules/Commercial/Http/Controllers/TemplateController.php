<?php

declare(strict_types=1);

namespace App\Modules\Commercial\Http\Controllers;

use App\Kernel\Audit\Audit;
use App\Modules\Commercial\Models\DocumentTemplate;
use App\Modules\Commercial\Models\DocumentTemplateVersion;
use App\Modules\Commercial\Services\PdfRenderer;
use App\Modules\Commercial\Services\TemplateRenderer;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Template Library: versions are append-only; only settings (title, colour, footer, default terms, bank block)
 * are edited here — layouts ship as reviewed Blade views (A-36). Documents keep the version they were issued with.
 *
 * @see SRS M03-07, M03-08
 */
final class TemplateController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', DocumentTemplate::class);

        return Inertia::render('Commercial/Templates/Index', [
            'templates' => DocumentTemplate::query()->with('versions')->orderBy('id')->get()->map(fn (DocumentTemplate $t): array => [
                'id' => $t->id, 'code' => $t->code, 'name' => $t->name, 'active' => $t->active,
                'versions' => $t->versions->map(fn (DocumentTemplateVersion $v): array => ['id' => $v->id, 'version' => $v->version, 'view_name' => $v->view_name, 'settings' => $v->settings, 'notes' => $v->notes, 'effective_from' => $v->effective_from->toIso8601String(),
                    'used_by' => (int) DB::table('commercial_documents')->where('template_version_id', $v->id)->count()])->values(),
            ]),
            'can' => ['edit' => $request->user()?->can('update', DocumentTemplate::class) ?? false],
        ]);
    }

    public function storeVersion(Request $request, DocumentTemplate $template): RedirectResponse
    {
        $this->authorize('update', $template);
        $data = $request->validate([
            'title' => ['required', 'string', 'max:60'], 'accent_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'], 'footer_text' => ['nullable', 'string', 'max:500'],
            'default_terms' => ['nullable', 'string', 'max:4000'], 'show_bank_details' => ['boolean'], 'notes' => ['nullable', 'string', 'max:500'],
        ]);
        $current = $template->versions()->first();
        $version = DB::transaction(fn () => DocumentTemplateVersion::query()->create([
            'document_template_id' => $template->id, 'version' => (int) ($current->version ?? 0) + 1, 'view_name' => $current->view_name ?? 'commercial.templates.standard-v1',
            'settings' => ['title' => $data['title'], 'accent_color' => $data['accent_color'], 'footer_text' => $data['footer_text'] ?? '', 'default_terms' => $data['default_terms'] ?? null, 'show_bank_details' => (bool) ($data['show_bank_details'] ?? false)],
            'notes' => $data['notes'] ?? null, 'effective_from' => now(), 'created_by' => $request->user()?->id,
        ]));
        Audit::log('version', $template, null, ['version' => $current?->version], ['version' => $version->version, 'settings' => $version->settings], $data['notes'] ?? null);

        return back()->with('success', __('Template :code version :v published. Already-issued documents keep their version.', ['code' => $template->code, 'v' => $version->version]));
    }

    public function preview(DocumentTemplateVersion $version, TemplateRenderer $templates, PdfRenderer $pdf): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorize('view', $version->template);

        return response($pdf->render($templates->previewHtml($version)), 200, [
            'Content-Type' => 'application/pdf', 'Content-Disposition' => 'inline; filename="template-preview.pdf"', 'Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
