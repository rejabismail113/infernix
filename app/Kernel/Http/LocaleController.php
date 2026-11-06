<?php

declare(strict_types=1);

namespace App\Kernel\Http;

use App\Kernel\Http\Middleware\SetLocale;
use App\Kernel\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Switch the interface language EN ↔ BM for the current user (NFR-12). */
final class LocaleController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $locale = $request->validate(['locale' => ['required', Rule::in(SetLocale::SUPPORTED)]])['locale'];
        $request->session()->put('locale', $locale);
        $user = $request->user();
        if ($user instanceof User) {
            User::withoutEvents(fn () => $user->forceFill(['locale' => $locale])->save());
        }

        return back();
    }
}
