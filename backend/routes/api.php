<?php

use App\Http\Controllers\Api\V1\Administration\MembershipController;
use App\Http\Controllers\Api\V1\Administration\ProjectController as AdministrationProjectController;
use App\Http\Controllers\Api\V1\Administration\TrackingValueController;
use App\Http\Controllers\Api\V1\Administration\UserController;
use App\Http\Controllers\Api\V1\AttachmentController;
use App\Http\Controllers\Api\V1\BugController;
use App\Http\Controllers\Api\V1\BugHistoryController;
use App\Http\Controllers\Api\V1\BugIndexController;
use App\Http\Controllers\Api\V1\BugRelationshipController;
use App\Http\Controllers\Api\V1\BugWorkflowController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\ProjectController;
use App\Http\Controllers\Api\V1\QAVerificationQueueController;
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

    Route::get('/bugs', [BugIndexController::class, 'index']);
    Route::post('/bugs', [BugController::class, 'store']);
    Route::get('/bugs/assigned-to-me', [BugController::class, 'assignedToMe']);
    Route::get('/bugs/qa-queue', [QAVerificationQueueController::class, 'index']);
    Route::get('/dashboards/summary', [DashboardController::class, 'summary']);
    Route::get('/bugs/{bug}', [BugController::class, 'show']);
    Route::patch('/bugs/{bug}', [BugController::class, 'update']);

    Route::post('/bugs/{bug}/begin-review', [BugWorkflowController::class, 'beginReview']);
    Route::post('/bugs/{bug}/information-requests', [BugWorkflowController::class, 'requestInformation']);
    Route::post('/bugs/{bug}/information-responses', [BugWorkflowController::class, 'respondToInformation']);
    Route::post('/bugs/{bug}/assignments', [BugWorkflowController::class, 'assign']);
    Route::post('/bugs/{bug}/priority', [BugWorkflowController::class, 'setPriority']);
    Route::post('/bugs/{bug}/severity', [BugWorkflowController::class, 'setSeverity']);
    Route::post('/bugs/{bug}/start-work', [BugWorkflowController::class, 'startWork']);
    Route::post('/bugs/{bug}/progress-updates', [BugWorkflowController::class, 'addProgress']);
    Route::post('/bugs/{bug}/fixed-resolutions', [BugWorkflowController::class, 'resolveFixed']);
    Route::post('/bugs/{bug}/non-fix-resolutions', [BugWorkflowController::class, 'recordNonFix']);
    Route::post('/bugs/{bug}/verifications', [BugWorkflowController::class, 'verify']);
    Route::post('/bugs/{bug}/resume-work', [BugWorkflowController::class, 'resumeWork']);
    Route::post('/bugs/{bug}/renew-review', [BugWorkflowController::class, 'renewReview']);
    Route::post('/bugs/{bug}/reopen', [BugWorkflowController::class, 'reopen']);

    Route::get('/bugs/{bug}/activity', [BugHistoryController::class, 'index']);
    Route::get('/bugs/{bug}/relationships', [BugRelationshipController::class, 'index']);
    Route::post('/bugs/{bug}/relationships', [BugRelationshipController::class, 'store']);
    Route::delete('/relationships/{relationship}', [BugRelationshipController::class, 'destroy']);

    Route::get('/bugs/{bug}/attachments', [AttachmentController::class, 'index']);
    Route::post('/bugs/{bug}/attachments', [AttachmentController::class, 'store']);
    Route::get('/attachments/{attachment}', [AttachmentController::class, 'show']);
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy']);
});
