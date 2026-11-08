<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUserAccount
{
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $lockedUser = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedUser->ownsTenants()) {
                throw ValidationException::withMessages([
                    'ownership' => __('Transfer ownership or delete your workspaces before deleting your account.'),
                ]);
            }

            $lockedUser->delete();
        });
    }
}
