<?php

declare(strict_types=1);

namespace App\Kernel\Identity\Http\Controllers;

use App\Kernel\Identity\Services\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** @see SRS G-01, M12-01 */
final class InvitationAcceptController
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(string $token): Response
    {
        $invitation = $this->invitations->findByToken($token) ?? throw new NotFoundHttpException;

        return Inertia::render('Auth/AcceptInvitation', [
            'token' => $token,
            'email' => $invitation->email,
            'name' => $invitation->name,
            'role' => $invitation->role->name,
        ]);
    }

    public function store(Request $request, string $token): RedirectResponse
    {
        $invitation = $this->invitations->findByToken($token) ?? throw new NotFoundHttpException;
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->numbers()],
        ]);
        $user = $this->invitations->accept($invitation, $data['name'], $data['password']);
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
