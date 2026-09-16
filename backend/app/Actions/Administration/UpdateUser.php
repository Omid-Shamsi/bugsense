<?php

namespace App\Actions\Administration;

use App\Actions\Concerns\RecordsActivity;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UpdateUser
{
    use RecordsActivity;

    /**
     * @param  array{email?: string, display_name?: string, password?: string, is_system_admin?: bool}  $data
     */
    public function handle(User $actor, User $target, array $data): User
    {
        return DB::transaction(function () use ($actor, $target, $data) {
            $before = $target->only(['email', 'display_name', 'is_system_admin']);

            if (array_key_exists('email', $data)) {
                $target->email = $data['email'];
            }
            if (array_key_exists('display_name', $data)) {
                $target->display_name = $data['display_name'];
            }
            if (array_key_exists('password', $data)) {
                $target->password = Hash::make($data['password']);
            }
            if (array_key_exists('is_system_admin', $data)) {
                $target->is_system_admin = $data['is_system_admin'];
            }

            $target->save();

            $this->recordAdministrativeEvent(
                actor: $actor,
                subjectType: 'user',
                subjectId: $target->id,
                eventType: 'user.updated',
                before: $before,
                after: $target->only(['email', 'display_name', 'is_system_admin']),
            );

            return $target;
        });
    }
}
