<?php

namespace App\Console\Commands;

use App\Models\Invitation;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

class BootstrapOwner extends Command
{
    protected $signature = 'trading:bootstrap-owner {email}';

    protected $description = 'Email a single-use invitation for the first owner; never print credentials';

    public function handle(): int
    {
        $email = strtolower($this->argument('email'));
        Validator::make(['email' => $email], ['email' => 'required|email|max:255|unique:users,email'])->validate();
        $issued = DB::transaction(function () use ($email) {
            // Serialize first-owner invitations on the shared owner role row.
            Role::where('name', 'owner')->where('guard_name', 'web')->lockForUpdate()->firstOrFail();
            if (User::role('owner')->exists() || Invitation::where('role', 'owner')->whereNull('accepted_at')->where('expires_at', '>', now())->exists()) {
                return false;
            }
            Invitation::issue($email, 'owner');

            return true;
        });
        if (! $issued) {
            $this->error('An owner or active owner invitation already exists.');

            return self::FAILURE;
        }
        $this->info('Owner invitation sent. Finish passkey and authenticator enrollment after acceptance.');

        return self::SUCCESS;
    }
}
