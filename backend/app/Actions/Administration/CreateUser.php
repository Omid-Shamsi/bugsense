<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateUser
{
    use RecordsActivity;

    /**
     * @param  array{email: string, display_name: string, password: string, is_system_admin?: bool}  $data
     */
    public function handle(User $actor, array $data): User
    {
        return DB::transaction(function () use ($actor, $data) {
            $user = User::create([
                'email' => $data['email'],
                'display_name' => $data['display_name'],
                'password' => Hash::make($data['password']),
                'is_system_admin' => $data['is_system_admin'] ?? false,
            ]);

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'user',
                subjectId: $user->id,
                eventType: 'user.created',
                after: $user->only(['email', 'display_name', 'is_system_admin']),
            );

            return $user;
        });
    }
}
