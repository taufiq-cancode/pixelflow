<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Grants a registered user access to the admin usage report.
 */
final class MakeAdmin extends Command
{
    protected $signature = 'pixelflow:make-admin {email : Email address of a registered user}';

    protected $description = 'Give a user access to the admin usage report';

    public function handle(): int
    {
        $user = User::query()->where('email', $this->argument('email'))->first();

        if ($user === null) {
            $this->error('No user has that email address. Register first, then run this again.');

            return self::FAILURE;
        }

        $user->forceFill(['is_admin' => true])->save();

        $this->info("{$user->email} can now open /admin/usage.");

        return self::SUCCESS;
    }
}
