<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use App\Kernel\Identity\Http\Middleware\EnsureMfa;
use App\Kernel\Identity\Http\Middleware\RequireRecentAuth;
use App\Kernel\Identity\Models\User;
use App\Kernel\Identity\Services\MfaService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** @see SRS G-01, M12-01, NFR-01 */
final class MfaController
{
    public function __construct(private readonly MfaService $mfa) {}

    public function setup(Request $request): Response|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->mfaEnabled()) {
            return redirect()->route('dashboard');
        }
        $enrolment = $this->mfa->beginEnrolment($user);

        return Inertia::render('Auth/MfaSetup', [
            'qrSvg' => $enrolment['qr_svg'],
            'secret' => $enrolment['secret'],
            'required' => $user->mfaRequired(),
        ]);
    }

    public function confirm(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'min:6', 'max:8']]);
        try {
            $codes = $this->mfa->confirmEnrolment($user, $data['code']);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }
        $request->session()->put(EnsureMfa::SESSION_KEY, now()->toIso8601String());
        RequireRecentAuth::confirm($request);

        return Inertia::render('Auth/MfaRecoveryCodes', ['codes' => $codes]);
    }

    public function challenge(Request $request): Response|RedirectResponse
    {
        if ($request->session()->has(EnsureMfa::SESSION_KEY)) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/MfaChallenge');
    }

    public function verify(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $data = $request->validate(['code' => ['required', 'string', 'min:6', 'max:12']]);
        if (! $this->mfa->verify($user, $data['code'])) {
            throw ValidationException::withMessages(['code' => __('Invalid authentication code.')]);
        }
        $request->session()->put(EnsureMfa::SESSION_KEY, now()->toIso8601String());
        RequireRecentAuth::confirm($request);

        return redirect()->intended(route('dashboard'));
    }
}
