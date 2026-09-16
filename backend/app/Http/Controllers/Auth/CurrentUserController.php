<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\CurrentUserResource;
use Illuminate\Http\Request;

class CurrentUserController extends Controller
{
    public function show(Request $request): CurrentUserResource
    {
        return new CurrentUserResource($request->user());
    }
}
