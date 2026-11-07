<?php

declare(strict_types=1);

use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\ClientContact;
use App\Modules\Projects\Services\ClientsDirectory;
use Illuminate\Database\QueryException;

/* Clients Management v2 (owner spec 2026-11-07). */

function clientPayload(array $over = []): array
{
    return $over + ['name' => 'Jabatan Digital Negara', 'client_type' => 'government', 'payment_terms_days' => 30, 'status' => 'active',
        'industry' => 'Government / Public Sector', 'tax_no' => 'EI00000000040', 'registration_no' => 'G-0001', 'einvoice_id_type' => 'brn', 'einvoice_id_no' => 'G-0001',
        'address' => ['line1' => 'Parcel B', 'city' => 'Putrajaya', 'state' => 'W.P. Putrajaya', 'country' => 'Malaysia'],
        'contacts' => [['name' => 'Encik Faizal', 'email' => 'faizal@jdn.test', 'is_primary' => false], ['name' => 'Puan Siti', 'position' => 'Procurement', 'is_primary' => true]]];
}

it('auto-generates Client IDs CLT-YYYY-NNN in sequence', function (): void {
    actingAsSatisfied(ownerUser())->post(route('projects.clients.store'), clientPayload())->assertSessionHasNoErrors();
    actingAsSatisfied(ownerUser())->post(route('projects.clients.store'), clientPayload(['name' => 'Second Co', 'client_type' => 'private']))->assertSessionHasNoErrors();
    $codes = Client::query()->whereIn('name', ['Jabatan Digital Negara', 'Second Co'])->orderBy('id')->pluck('client_code')->all();
    $year = now()->timezone('Asia/Kuala_Lumpur')->format('Y');
    expect($codes[0])->toMatch("/^CLT-{$year}-\\d{3}$/")
        ->and((int) substr($codes[1], -3))->toBe((int) substr($codes[0], -3) + 1);
})->group('M02-01');

it('saves several contacts with one primary, mirrored to the client for documents', function (): void {
    actingAsSatisfied(ownerUser())->post(route('projects.clients.store'), clientPayload())->assertSessionHasNoErrors();
    $client = Client::query()->where('name', 'Jabatan Digital Negara')->firstOrFail();
    expect($client->contacts)->toHaveCount(2)->and($client->contacts->first()->name)->toBe('Puan Siti')
        ->and($client->contact_person)->toBe('Puan Siti')->and($client->typeLabel())->toBe('Government');

    // Edit: drop a contact, make the other primary.
    $faizal = ClientContact::query()->where('name', 'Encik Faizal')->firstOrFail();
    actingAsSatisfied(ownerUser())->put(route('projects.clients.update', $client), clientPayload(['contacts' => [['id' => $faizal->id, 'name' => 'Encik Faizal', 'is_primary' => true]]]))
        ->assertSessionHasNoErrors();
    expect($client->fresh()->contacts)->toHaveCount(1)->and($client->fresh()->contact_person)->toBe('Encik Faizal');

    $snap = app(ClientsDirectory::class)->snapshot($client->id);
    expect($snap)->toMatchArray(['client_code' => $client->client_code, 'einvoice_id_type' => 'brn', 'tax_no' => 'EI00000000040']);
})->group('M02-01', 'M03-01');

it('validates type (Others → specify), status and e-invoice ID type; prospects allowed', function (): void {
    actingAsSatisfied(ownerUser())->post(route('projects.clients.store'), clientPayload(['client_type' => 'other']))->assertSessionHasErrors('client_type_other');
    actingAsSatisfied(ownerUser())->post(route('projects.clients.store'), clientPayload(['einvoice_id_type' => 'driving']))->assertSessionHasErrors('einvoice_id_type');
    actingAsSatisfied(ownerUser())->post(route('projects.clients.store'), clientPayload(['status' => 'prospect', 'client_type' => 'other', 'client_type_other' => 'Cooperative']))
        ->assertSessionHasNoErrors();
    $c = Client::query()->latest('id')->firstOrFail();
    expect($c->status)->toBe('prospect')->and($c->typeLabel())->toBe('Cooperative');
    expect(fn () => $c->forceFill(['client_type' => 'alien'])->save())->toThrow(QueryException::class);
})->group('M02-01');

it('only users allowed to create clients can add one', function (): void {
    actingAsSatisfied(userWithRoles('STF'))->post(route('projects.clients.store'), clientPayload())->assertForbidden();
})->group('M02-01', 'NFR-02');
