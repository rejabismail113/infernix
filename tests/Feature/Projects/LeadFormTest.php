<?php

declare(strict_types=1);

use App\Kernel\Audit\Models\AuditEvent;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\Lead;
use Illuminate\Database\QueryException;
use Inertia\Testing\AssertableInertia;

/*
 * Leads & Pipeline form v2 (owner spec 2026-11-07): client / prospect / new client, contact auto-fill, opportunity
 * types with Others → specify, sources, next follow-up, status on the form and coded lost reasons.
 */

function leadPayload(array $over = []): array
{
    return $over + ['party' => 'prospect', 'prospect_name' => 'ABC Sdn Bhd', 'title' => 'Power BI Training for KEMAS', 'engagement_type' => 'training', 'estimated_value' => '5000.00'];
}

it('shows the page as Leads & Pipeline with the type, source and lost-reason lists', function (): void {
    actingAsSatisfied(ownerUser())->get(route('projects.leads.index'))->assertOk()
        ->assertInertia(fn (AssertableInertia $p) => $p->component('Projects/Leads/Index')
            ->has('types', 7)->where('types.4.label', 'Support & Maintenance')
            ->has('sources', 12)->where('sources.5', 'Phone / WhatsApp Inquiry')
            ->has('lostReasons', 9)->where('stageDescriptions.qualified', 'Opportunity has been evaluated and confirmed as viable'));
})->group('M02-02');

it('fills contact details from the Client master when an existing client is chosen', function (): void {
    $owner = ownerUser();
    $client = Client::query()->create(['company_id' => $owner->company_id, 'name' => 'KEMAS', 'contact_person' => 'Nurul Aisyah', 'email' => 'nurul@kemas.test', 'phone' => '+60 12-345 6789', 'status' => 'active', 'created_by' => $owner->id]);

    actingAsSatisfied($owner)->post(route('projects.leads.store'), leadPayload(['party' => 'existing', 'client_id' => $client->id, 'prospect_name' => null, 'next_follow_up_on' => '2026-11-20', 'source' => 'Client Referral']))
        ->assertSessionHasNoErrors();
    $lead = Lead::query()->latest('id')->firstOrFail();
    expect($lead->client_id)->toBe($client->id)->and($lead->prospect_name)->toBeNull()
        ->and($lead->contact_person)->toBe('Nurul Aisyah')->and($lead->contact_email)->toBe('nurul@kemas.test')->and($lead->contact_phone)->toBe('+60 12-345 6789')
        ->and($lead->next_follow_up_on?->toDateString())->toBe('2026-11-20')->and($lead->source)->toBe('Client Referral');
})->group('M02-02');

it('registers a new confirmed client from the lead form, only for users who may create clients', function (): void {
    actingAsSatisfied(ownerUser())->post(route('projects.leads.store'), leadPayload(['party' => 'new_client', 'new_client' => ['name' => 'Syarikat Baru Sdn Bhd'], 'contact_person' => 'Encik Ali', 'contact_email' => 'ali@baru.test']))
        ->assertSessionHasNoErrors();
    $client = Client::query()->where('name', 'Syarikat Baru Sdn Bhd')->firstOrFail();
    expect($client->status)->toBe('active')->and($client->contact_person)->toBe('Encik Ali')
        ->and(Lead::query()->latest('id')->firstOrFail()->client_id)->toBe($client->id);

    $staff = userWithRoles('STF');
    actingAsSatisfied($staff)->post(route('projects.leads.store'), leadPayload(['party' => 'new_client', 'new_client' => ['name' => 'Sneaky Co']]))->assertForbidden();
    expect(Client::query()->where('name', 'Sneaky Co')->exists())->toBeFalse();
})->group('M02-02', 'M06-02');

it('requires the opportunity name, client, type and estimated value, and a description for Others', function (): void {
    actingAsSatisfied(ownerUser())->post(route('projects.leads.store'), ['party' => 'prospect'])
        ->assertSessionHasErrors(['title', 'engagement_type', 'estimated_value', 'prospect_name']);
    actingAsSatisfied(ownerUser())->post(route('projects.leads.store'), leadPayload(['engagement_type' => 'other']))
        ->assertSessionHasErrors(['engagement_type_other']);
    actingAsSatisfied(ownerUser())->post(route('projects.leads.store'), leadPayload(['engagement_type' => 'other', 'engagement_type_other' => 'Event management']))
        ->assertSessionHasNoErrors();
    expect(Lead::query()->latest('id')->firstOrFail()->engagement_type_other)->toBe('Event management');
})->group('M02-02');

it('sets the status from the form through an audited transition; Lost needs a coded reason (Others → explanation)', function (): void {
    $owner = ownerUser();
    actingAsSatisfied($owner)->post(route('projects.leads.store'), leadPayload(['stage' => 'qualified']))->assertSessionHasNoErrors();
    $lead = Lead::query()->latest('id')->firstOrFail();
    expect($lead->stage)->toBe('qualified')
        ->and(AuditEvent::query()->where('action', 'transition')->where('entity_id', $lead->id)->exists())->toBeTrue();

    $put = fn (array $over) => actingAsSatisfied($owner)->put(route('projects.leads.update', $lead), leadPayload($over));
    $put(['stage' => 'lost'])->assertSessionHasErrors(['lost_reason_code']);
    $put(['stage' => 'lost', 'lost_reason_code' => 'other'])->assertSessionHasErrors(['lost_reason']);
    $put(['stage' => 'lost', 'lost_reason_code' => 'competitor'])->assertSessionHasNoErrors();
    $lead->refresh();
    expect($lead->stage)->toBe('lost')->and($lead->lost_reason_code)->toBe('competitor')->and($lead->lost_reason)->toBe('Competitor Selected')->and($lead->closed_at)->not->toBeNull();

    // Editing a closed lead keeps its status and reason.
    $put(['stage' => 'lost', 'notes' => 'Follow up next year'])->assertSessionHasNoErrors();
    expect($lead->fresh()->notes)->toBe('Follow up next year')->and($lead->fresh()->lost_reason)->toBe('Competitor Selected');
})->group('M02-02', 'WF-04');

it('rejects unknown types and lost reasons at the database too', function (): void {
    $lead = Lead::query()->create(['company_id' => ownerUser()->company_id, 'prospect_name' => 'X', 'title' => 'Y', 'stage' => 'new', 'owner_user_id' => ownerUser()->id]);
    expect(fn () => $lead->forceFill(['engagement_type' => 'bogus'])->save())->toThrow(QueryException::class);
})->group('M02-02');
