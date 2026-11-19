<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PromoteUser extends Command
{
    protected $signature = 'user:promote {email : Email of an existing user}';

    protected $description = 'Make an existing user an admin';

    public function handle(): int
    {
        $user = User::query()->whereRaw('lower(email) = ?', [strtolower(trim((string) $this->argument('email')))])->first();

        if ($user === null) {
            $this->error('No user with that email. Create one with user:create.');

            return self::FAILURE;
        }

        if ($user->is_admin) {
            $this->info("{$user->email} is already an admin.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_admin' => true])->save();
        $this->info("{$user->email} is now an admin.");

        return self::SUCCESS;
    }
}
