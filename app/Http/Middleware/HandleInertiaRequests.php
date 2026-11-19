<?php

namespace App\Http\Middleware;

use App\Events\GenerationUpdated;
use App\Support\Decimal;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
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
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'credits' => $request->user() === null ? null : Inertia::live(
                fn (): array => [
                    'balance' => Decimal::fromDatabase($request->user()->balance),
                    'available' => $request->user()->availableCredits(),
                    'packages' => $this->packages(),
                ],
                // Starting, finishing or failing a generation all move the balance.
                on: GenerationUpdated::class,
                channel: new PrivateChannel("users.{$request->user()->id}.generations"),
            ),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * The fake credit packages a user can pick from.
     *
     * @return list<array{id: string, amount: string}>
     */
    private function packages(): array
    {
        $packages = [];

        foreach ((array) config('credits.packages') as $id => $amount) {
            $packages[] = ['id' => (string) $id, 'amount' => (string) $amount];
        }

        return $packages;
    }
}
