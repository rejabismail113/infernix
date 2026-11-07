<?php

declare(strict_types=1);

namespace App\Modules\Projects\Services;

use App\Kernel\Identity\Models\User;
use App\Modules\Projects\Exceptions\ProjectRejected;
use App\Modules\Projects\Models\Client;
use App\Modules\Projects\Models\ClientContact;
use Illuminate\Support\Facades\DB;

/**
 * Client master create / edit with several contact persons, in one transaction. The primary contact (or the first
 * one) is mirrored to clients.contact_person so quotations / invoices keep showing a contact (M03-01 snapshot).
 * Issued documents keep their own snapshot; edits here never change them (M03-07).
 *
 * @see SRS M02-01, M03-01, M03-07
 */
final class ClientService
{
    /** @param  array<string, mixed>  $data */
    public function save(array $data, User $actor, ?Client $client = null): Client
    {
        return DB::transaction(function () use ($data, $actor, $client): Client {
            /** @var list<array<string, mixed>> $contacts */
            $contacts = array_values(array_filter($data['contacts'] ?? [], fn ($c): bool => is_array($c) && trim((string) ($c['name'] ?? '')) !== ''));
            unset($data['contacts']);
            if (($data['client_type'] ?? null) === 'other' && trim((string) ($data['client_type_other'] ?? '')) === '') {
                throw new ProjectRejected('Specify the client type.');
            }
            if (($data['client_type'] ?? null) !== 'other') {
                $data['client_type_other'] = null;
            }
            $primary = null;
            foreach ($contacts as $i => $c) {
                if (! empty($c['is_primary'])) {
                    $primary = $i;
                    break;
                }
            }
            $primary ??= $contacts === [] ? null : 0;
            $data['contact_person'] = $primary === null ? null : trim((string) $contacts[$primary]['name']);

            if ($client === null) {
                $client = Client::query()->create($data + ['company_id' => $actor->company_id, 'created_by' => $actor->id]);
            } else {
                $client->fill($data)->save();
            }

            $keep = [];
            foreach ($contacts as $i => $c) {
                $attrs = [
                    'name' => trim((string) $c['name']), 'position' => ($c['position'] ?? null) ?: null, 'email' => ($c['email'] ?? null) ?: null,
                    'phone' => ($c['phone'] ?? null) ?: null, 'is_primary' => false, 'sort_order' => $i,
                ];
                $row = ! empty($c['id']) ? ClientContact::query()->where('client_id', $client->id)->find($c['id']) : null;
                if ($row instanceof ClientContact) {
                    $row->update($attrs);
                } else {
                    $row = ClientContact::query()->create($attrs + ['client_id' => $client->id]);
                }
                $keep[$i] = $row->id;
            }
            ClientContact::query()->where('client_id', $client->id)->whereNotIn('id', array_values($keep) ?: [0])->get()->each->delete();
            if ($primary !== null) {
                ClientContact::query()->whereKey($keep[$primary])->update(['is_primary' => true]);
            }

            return $client->refresh();
        });
    }
}
