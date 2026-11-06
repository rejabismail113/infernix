<?php

declare(strict_types=1);

namespace App\Kernel\Search\Http;

use App\Kernel\Identity\Models\User;
use App\Kernel\Search\SearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Global search (any signed-in user). Every hit is re-authorised against the record's policy (deny-by-default),
 * and the index itself only ever holds redacted metadata (M13-05).
 *
 * @see SRS G-04, M13-05, NFR-02
 */
final class SearchController
{
    public function __invoke(Request $request, SearchService $search): JsonResponse|Response
    {
        /** @var User $user */
        $user = $request->user();
        $q = mb_substr((string) $request->query('q', ''), 0, 120);
        $results = $search->search($user, $q);

        return $request->wantsJson() ? response()->json(['results' => $results]) : Inertia::render('Search/Index', ['q' => $q, 'results' => $results]);
    }
}
