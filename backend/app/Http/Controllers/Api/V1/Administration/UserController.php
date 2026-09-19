<?php

namespace App\Http\Controllers\Api\V1\Administration;

use App\Actions\Administration\CreateUser;
use App\Actions\Administration\DeactivateUser;
use App\Actions\Administration\UpdateUser;
use App\Http\Requests\Api\V1\Administration\CreateUserRequest;
use App\Http\Requests\Api\V1\Administration\UpdateUserRequest;
use App\Http\Resources\Api\V1\UserAdminResource;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends AdministrationController
{
    public function index(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $query = User::query()->orderBy('display_name');

        if ($request->filled('q')) {
            $term = '%'.$request->string('q')->toString().'%';
            $query->where(function ($q) use ($term) {
                $q->where('email', 'ilike', $term)->orWhere('display_name', 'ilike', $term);
            });
        }

        $perPage = min((int) $request->integer('per_page', 25), 100);

        return UserAdminResource::collection($query->paginate($perPage));
    }

    public function store(CreateUserRequest $request, CreateUser $action)
    {
        $this->authorize('create', User::class);

        $user = $action->handle($request->user(), $request->validated());

        return UserAdminResource::make($user)->response()->setStatusCode(201);
    }

    public function show(User $user)
    {
        $this->authorize('view', $user);

        return UserAdminResource::make($user);
    }

    public function update(UpdateUserRequest $request, User $user, UpdateUser $action, DeactivateUser $deactivateAction)
    {
        $this->authorize('update', $user);

        $data = $request->validated();

        if (($data['active'] ?? null) === false) {
            $user = $deactivateAction->handle($request->user(), $user, (array) $request->input('remediation', []));
        } else {
            unset($data['active']);
            $user = $action->handle($request->user(), $user, $data);
        }

        return UserAdminResource::make($user);
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('update', $user);

        return $this->rejectHardDeletion($request);
    }
}
