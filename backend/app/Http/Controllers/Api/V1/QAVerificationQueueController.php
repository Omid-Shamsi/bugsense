<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\BugResource;
use App\Queries\QAVerificationQueue;
use Illuminate\Http\Request;

class QAVerificationQueueController extends Controller
{
    public function index(Request $request)
    {
        $bugs = (new QAVerificationQueue())->forUser($request->user())
            ->with(['project', 'reporter', 'category', 'priority', 'severity', 'tags', 'assigneeMembership.user', 'activeResolutionAttempt.recordedBy', 'openInformationRequest.requestedBy'])
            ->orderByDesc('updated_at')
            ->get();

        return BugResource::collection($bugs);
    }
}
