<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class RevokeAdmin extends Command
{
    protected $signature = 'user:revoke-admin {email : Email of an admin}';

    protected $description = 'Remove admin rights from a user (the account itself stays)';

    public function handle(): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower(trim((string) $this->argument('email')))])->first();

        if ($user === null) {
            $this->error('No user with that email.');

            return self::FAILURE;
        }

        if (! $user->is_admin) {
            $this->info("{$user->email} is not an admin.");

            return self::SUCCESS;
        }

        // Nobody could undo this from the app, so keep at least one admin.
        if (User::query()->where('is_admin', true)->count() <= 1) {
            $this->error('That is the only admin. Promote someone else first.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => false])->save();
        $this->info("{$user->email} is no longer an admin.");

        return self::SUCCESS;
    }
}
