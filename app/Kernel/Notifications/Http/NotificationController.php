<?php

declare(strict_types=1);

namespace App\Kernel\Notifications\Http;

use App\Kernel\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

/** In-app bell: a user only ever sees and marks their own notifications. @see SRS G-05 */
final class NotificationController
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        return Inertia::render('Notifications/Index', [
            'notifications' => $user->notifications()->latest()->limit(100)->get()->map(fn (DatabaseNotification $n): array => [
                'id' => $n->id, 'title' => $n->data['title'] ?? '', 'body' => $n->data['body'] ?? '', 'url' => $n->data['url'] ?? null, 'event' => $n->data['event'] ?? null,
                'read' => $n->read_at !== null, 'created_at' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        /** @var DatabaseNotification $n */
        $n = $user->notifications()->whereKey($notification)->firstOrFail();
        $n->markAsRead();
        $url = (string) ($n->data['url'] ?? '');

        // Only same-app links are followed (no open redirect).
        return str_starts_with($url, url('/')) ? redirect()->to($url) : redirect()->route('notifications.index');
    }

    public function readAll(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
