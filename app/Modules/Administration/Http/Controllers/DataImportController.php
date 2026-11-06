<?php

declare(strict_types=1);

namespace App\Modules\Administration\Http\Controllers;

use App\Kernel\Identity\Enums\PermissionAction;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\PermissionService;
use App\Modules\Administration\Services\DataImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Data migration (§9.1): download a template, validate a CSV (dry run), then import — Owner only, audited.
 *
 * @see SRS §9.1
 */
final class DataImportController
{
    public function index(Request $request, PermissionService $permissions): Response
    {
        $this->guard($request, $permissions);

        return Inertia::render('Admin/DataImport', ['types' => DataImportService::TYPES]);
    }

    public function template(Request $request, PermissionService $permissions, string $type): HttpResponse
    {
        $this->guard($request, $permissions);
        abort_unless(isset(DataImportService::TYPES[$type]), 404);

        return response(DataImportService::template($type), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"import-{$type}.csv\""]);
    }

    public function run(Request $request, PermissionService $permissions, DataImportService $imports): RedirectResponse
    {
        $user = $this->guard($request, $permissions);
        $data = $request->validate(['type' => ['required', Rule::in(array_keys(DataImportService::TYPES))], 'file' => ['required', 'file', 'max:5120', 'mimes:csv,txt'],
            'dry_run' => ['boolean'], 'date' => ['nullable', 'date']]);
        $report = $imports->run($data['type'], (string) $request->file('file')?->get(), $user, (bool) ($data['dry_run'] ?? true), $data['date'] ?? null);

        return back()->with('data_import', $report);
    }

    private function guard(Request $request, PermissionService $permissions): User
    {
        /** @var User $user */
        $user = $request->user();
        abort_unless($permissions->can($user, PermissionAction::Create, 'data_import'), 403);

        return $user;
    }
}
