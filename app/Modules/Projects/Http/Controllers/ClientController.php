<?php

declare(strict_types=1);

namespace App\Modules\Projects\Http\Controllers;

use App\Kernel\Http\HandlesDomainErrors;
use App\Kernel\Identity\Models\User;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\ClientContact;
use App\Modules\Projects\Services\ClientService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Clients Management (client master / CRM): Client ID, type, status (prospect / active / inactive), industry,
 * registration, TIN, SST, e-Invoice buyer identification, address and several contact persons.
 * Issued documents keep their own snapshot. @see SRS M02-01, M03-01, §6.2
 */
final class ClientController
{
    use AuthorizesRequests;
    use HandlesDomainErrors;

    /** Malaysian states and federal territories for the State dropdown (other countries: free text). */
    public const MY_STATES = ['Johor', 'Kedah', 'Kelantan', 'Melaka', 'Negeri Sembilan', 'Pahang', 'Perak', 'Perlis', 'Pulau Pinang', 'Sabah', 'Sarawak',
        'Selangor', 'Terengganu', 'W.P. Kuala Lumpur', 'W.P. Labuan', 'W.P. Putrajaya'];

    /** Country dropdown: Malaysia first (default), then common partners; "Others" lets the user type. PLACEHOLDER list. */
    public const COUNTRIES = ['Malaysia', 'Brunei', 'Singapore', 'Indonesia', 'Thailand', 'Philippines', 'Vietnam', 'China', 'Hong Kong', 'India', 'Japan',
        'South Korea', 'Australia', 'United Arab Emirates', 'Saudi Arabia', 'United Kingdom', 'United States', 'Others'];

    public function __construct(private readonly ClientService $service) {}

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Client::class);
        $f = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(Client::STATUSES)]]);

        return Inertia::render('Projects/Clients/Index', [
            'clients' => Client::query()->with('contacts')
                ->when($f['q'] ?? null, fn ($q, $v) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$v}%")->orWhere('registration_no', 'ilike', "%{$v}%")
                    ->orWhere('email', 'ilike', "%{$v}%")->orWhere('client_code', 'ilike', "%{$v}%")))
                ->when($f['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->withCount('projects')->orderBy('name')->limit(500)->get()
                ->map(fn (Client $c): array => [
                    'id' => $c->id, 'client_code' => $c->client_code, 'name' => $c->name, 'client_type' => $c->client_type, 'client_type_other' => $c->client_type_other,
                    'type_label' => $c->typeLabel(), 'industry' => $c->industry, 'registration_no' => $c->registration_no, 'tax_no' => $c->tax_no, 'sst_no' => $c->sst_no,
                    'einvoice_id_type' => $c->einvoice_id_type, 'einvoice_id_no' => $c->einvoice_id_no, 'email' => $c->email, 'phone' => $c->phone,
                    'contact_person' => $c->contact_person, 'address' => $c->address, 'address_line' => $c->addressLine(), 'payment_terms_days' => $c->payment_terms_days,
                    'status' => $c->status, 'projects' => (int) $c->getAttribute('projects_count'),
                    'contacts' => $c->contacts->map(fn (ClientContact $k): array => ['id' => $k->id, 'name' => $k->name, 'position' => $k->position, 'email' => $k->email, 'phone' => $k->phone, 'is_primary' => $k->is_primary])->values(),
                ]),
            'filters' => $f,
            'options' => [
                'types' => collect(Client::TYPES)->map(fn (string $l, string $v): array => ['value' => $v, 'label' => $l])->values(),
                'industries' => Client::INDUSTRIES, 'states' => self::MY_STATES, 'countries' => self::COUNTRIES,
                'idTypes' => collect(Client::EINVOICE_ID_TYPES)->map(fn (string $l, string $v): array => ['value' => $v, 'label' => $l])->values(),
            ],
            'can' => ['create' => $request->user()?->can('create', Client::class) ?? false, 'edit' => $request->user()?->can('update', Client::class) ?? false],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Client::class);
        $data = $this->validated($request) + ['status' => 'active'];

        $client = null;
        $response = $this->attempt(function () use ($request, $data, &$client): void {
            $client = $this->service->save($data, $this->user($request));
        }, '');

        return $client instanceof Client
            ? back()->with('success', __('Client :name (:code) added.', ['name' => $client->name, 'code' => $client->client_code]))->with('created_client_id', $client->id)
            : $response;
    }

    public function update(Request $request, Client $client): RedirectResponse
    {
        $this->authorize('update', $client);

        return $this->attempt(fn () => $this->service->save($this->validated($request), $this->user($request), $client),
            __('Client updated. Documents already issued keep the details they were issued with.'));
    }

    private function user(Request $request): User
    {
        /** @var User $u */
        $u = $request->user();

        return $u;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $d = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'client_type' => ['required', Rule::in(array_keys(Client::TYPES))],
            'client_type_other' => ['nullable', 'required_if:client_type,other', 'string', 'max:120'],
            'status' => ['sometimes', 'required', Rule::in(Client::STATUSES)],
            'industry' => ['nullable', 'string', 'max:120'],
            'registration_no' => ['nullable', 'string', 'max:60'], 'tax_no' => ['nullable', 'string', 'max:60'], 'sst_no' => ['nullable', 'string', 'max:40'],
            'einvoice_id_type' => ['nullable', Rule::in(array_keys(Client::EINVOICE_ID_TYPES))], 'einvoice_id_no' => ['nullable', 'string', 'max:60'],
            'email' => ['nullable', 'email', 'max:190'], 'phone' => ['nullable', 'string', 'max:40'],
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:365'],
            'address.line1' => ['nullable', 'string', 'max:190'], 'address.line2' => ['nullable', 'string', 'max:190'], 'address.postcode' => ['nullable', 'string', 'max:10'],
            'address.city' => ['nullable', 'string', 'max:100'], 'address.state' => ['nullable', 'string', 'max:100'], 'address.country' => ['nullable', 'string', 'max:100'],
            'contacts' => ['nullable', 'array', 'max:20'],
            'contacts.*.id' => ['nullable', 'integer'],
            'contacts.*.name' => ['nullable', 'string', 'max:190'],
            'contacts.*.position' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email', 'max:190'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.is_primary' => ['nullable', 'boolean'],
        ], [
            'client_type_other.required_if' => __('Specify the client type.'),
        ]);
        $d['address'] = array_filter($d['address'] ?? [], fn ($v) => is_string($v) && $v !== '') ?: null;

        return $d;
    }
}
