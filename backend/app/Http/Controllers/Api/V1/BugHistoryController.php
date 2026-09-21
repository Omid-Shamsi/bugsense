<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityEventResource;
use App\Models\Bug;
use App\Queries\ActivityHistoryQuery;
use App\Queries\VisibleBugs;
use Illuminate\Http\Request;

class BugHistoryController extends Controller
{
    public function index(Request $request, Bug $bug)
    {
        abort_unless((new VisibleBugs())->forUser($request->user())->whereKey($bug->id)->exists(), 404);

        $perPage = min((int) $request->integer('per_page', 50), 200);

        $events = (new ActivityHistoryQuery())->forBug($bug)->with('actor')->paginate($perPage);

        return ActivityEventResource::collection($events);
    }
}
