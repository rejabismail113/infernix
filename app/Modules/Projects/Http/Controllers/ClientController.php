<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Modules\Projects\Models\Client;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Client master (CRM). Issued documents keep their own snapshot. @see SRS M02-01, M03-01 */
final class ClientController
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'in:active,inactive']]);

        return Inertia::render('Projects/Clients/Index', [
            'clients' => Client::query()->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$v}%")->orWhere('registration_no', 'ilike', "%{$v}%")->orWhere('email', 'ilike', "%{$v}%")))
                ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->withCount('projects')->orderBy('name')->limit(500)->get()
                ->map(fn (Client $c): array => ['id' => $c->id, 'name' => $c->name, 'registration_no' => $c->registration_no, 'tax_no' => $c->tax_no, 'email' => $c->email, 'phone' => $c->phone,
                    'contact_person' => $c->contact_person, 'address' => $c->address, 'address_line' => $c->addressLine(), 'payment_terms_days' => $c->payment_terms_days, 'status' => $c->status, 'projects' => (int) $c->getAttribute('projects_count')]),
            'filters' => $f,
            'can' => ['create' => $request->user()?->can('create', Client::class) ?? false, 'edit' => $request->user()?->can('update', Client::class) ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);
        $client = Client::query()->create($this->validated($request) + ['company_id' => $request->user()?->company_id, 'created_by' => $request->user()?->id, 'status' => 'active']);

        return back()->with('success', __('Client :name added.', ['name' => $client->name]))->with('created_client_id', $client->id);
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);
        $data = $this->validated($request) + $request->validate(['status' => ['required', 'in:active,inactive']]);
        $client->update($data);

        return back()->with('success', __('Client updated. Documents already issued keep the details they were issued with.'));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:190'], 'registration_no' => ['nullable', 'string', 'max:60'], 'tax_no' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'], 'contact_person' => ['nullable', 'string', 'max:190'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'address.line1' => ['nullable', 'string', 'max:190'], 'address.line2' => ['nullable', 'string', 'max:190'], 'address.postcode' => ['nullable', 'string', 'max:10'],
            'address.city' => ['nullable', 'string', 'max:100'], 'address.state' => ['nullable', 'string', 'max:100'], 'address.country' => ['nullable', 'string', 'max:100'],
        ]);
        $d['address'] = array_filter($d['address'] ?? [], fn ($v) => is_string($v) && $v !== '') ?: null;

        return $d;
    }
}
