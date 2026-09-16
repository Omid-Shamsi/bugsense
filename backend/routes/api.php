<?php

use App\Http\Controllers\Api\V1\Administration\MembershipController;
use App\Http\Controllers\Api\V1\Administration\ProjectController as AdministrationProjectController;
use App\Http\Controllers\Api\V1\Administration\TrackingValueController;
use App\Http\Controllers\Api\V1\Administration\UserController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Auth\CurrentUserController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('auth:sanctum')->group(function () {
    Route::get('/me', [CurrentUserController::class, 'show']);

    Route::get('/users', [UserController::class, 'index']);
    Route::post('/users', [UserController::class, 'store']);
    Route::get('/users/{user}', [UserController::class, 'show']);
    Route::patch('/users/{user}', [UserController::class, 'update']);
    Route::delete('/users/{user}', [UserController::class, 'destroy']);

    Route::get('/projects', [ProjectController::class, 'index']);
    Route::post('/projects', [AdministrationProjectController::class, 'store']);
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::patch('/projects/{project}', [AdministrationProjectController::class, 'update']);
    Route::delete('/projects/{project}', [AdministrationProjectController::class, 'destroy']);

    Route::get('/projects/{project}/memberships', [MembershipController::class, 'index']);
    Route::post('/projects/{project}/memberships', [MembershipController::class, 'store']);
    Route::patch('/memberships/{membership}', [MembershipController::class, 'update']);
    Route::delete('/memberships/{membership}', [MembershipController::class, 'destroy']);

    Route::get('/projects/{project}/tracking-values', [TrackingValueController::class, 'index']);
    Route::post('/projects/{project}/tracking-values', [TrackingValueController::class, 'store']);
    Route::patch('/tracking-values/{trackingValue}', [TrackingValueController::class, 'update']);
    Route::delete('/tracking-values/{trackingValue}', [TrackingValueController::class, 'destroy']);
});
