<?php

namespace App\Http\Controllers;

use App\Events\GenerationUpdated;
use App\Services\Dashboard\UserDashboard;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\LiveProp;
use Inertia\Response;

class DashboardController extends Controller
{
    /**
     * The signed-in user's summary. Every prop refreshes live when one of their
     * generations changes, on the same channel the history page uses.
     */
    public function __invoke(Request $request, UserDashboard $dashboard): Response
    {
        $user = $request->user();
        $channel = new PrivateChannel("users.{$user->id}.generations");

        $live = fn (callable $value): LiveProp => Inertia::live($value, on: GenerationUpdated::class, channel: $channel);

        return Inertia::render('Dashboard', [
            'stats' => $live(fn () => $dashboard->stats($user)),
            'dailySpend' => $live(fn () => $dashboard->dailySpend($user)),
            'recent' => $live(fn () => $dashboard->recent($user)),
        ]);
    }
}
