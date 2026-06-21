<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            // The shared demo prop (slice-008 §D / CONTRACT §D): null for a real session,
            // and { is_demo, expires_at } for a demo session — driving the demo banner. The
            // server-only demo_session_id / preset token is NEVER shared (it must not reach
            // the client). Snake_case per the project convention.
            'demo' => $this->demoProp($request),
        ];
    }

    /**
     * The demo-session banner prop: null for a real session, else the public
     * { is_demo, expires_at } shape (no server-only token ever exposed).
     *
     * @return array{is_demo: bool, expires_at: int|null}|null
     */
    private function demoProp(Request $request): ?array
    {
        if ($request->session()->get('is_demo') !== true) {
            return null;
        }

        $expiresAt = $request->session()->get('demo_expires_at');

        return [
            'is_demo' => true,
            'expires_at' => is_int($expiresAt) ? $expiresAt : null,
        ];
    }
}
