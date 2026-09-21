<?php

namespace App\Queries;

use App\Enums\BugStatus;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Dashboard aggregates computed from the exact same permission-filtered,
 * filtered query as BugQuery (FR-039) — never a separately hand-written
 * filter path, so list and dashboard can never drift apart.
 */
class DashboardQuery
{
    public function __construct(private readonly BugQuery $bugQuery = new BugQuery()) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function summarize(User $actor, array $filters): array
    {
        $base = $this->bugQuery->applyFilters($this->bugQuery->forUser($actor), $filters);

        return [
            'context' => [
                'filters' => $filters,
                'generated_at' => now()->toISOString(),
            ],
            'counts' => $this->counts($actor, $base),
            'breakdowns' => [
                'severity' => $this->trackingBreakdown($base, 'severity_id'),
                'category' => $this->trackingBreakdown($base, 'category_id'),
                'project' => $this->projectBreakdown($base),
                'developer' => $this->developerBreakdown($base),
            ],
            'average_resolution_time' => $this->resolutionTime($base),
        ];
    }

    /**
     * @param  Builder<\App\Models\Bug>  $base
     * @return array<string, int>
     */
    private function counts(User $actor, Builder $base): array
    {
        $total = (clone $base)->count();

        $statusCounts = (clone $base)
            ->select('status', DB::raw('count(*) as aggregate'))
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $statusCount = fn (BugStatus $status): int => (int) ($statusCounts[$status->value] ?? 0);

        $assignedToCurrentDeveloper = (clone $base)
            ->where('status', '!=', BugStatus::Closed->value)
            ->whereIn('assignee_membership_id', Membership::query()->where('user_id', $actor->id)->select('id'))
            ->count();

        return [
            // FR-039: totals here always agree with the same-filter Bug list.
            'total' => $total,
            // FR-042: open means any status except Closed.
            'open' => $total - $statusCount(BugStatus::Closed),
            // FR-042: awaiting review means Submitted or Review.
            'awaiting_review' => $statusCount(BugStatus::Submitted) + $statusCount(BugStatus::Review),
            // FR-042: awaiting QA includes Resolved and QA Verification,
            // each also reported separately below.
            'awaiting_qa' => $statusCount(BugStatus::Resolved) + $statusCount(BugStatus::QaVerification),
            'resolved' => $statusCount(BugStatus::Resolved),
            'qa_verification' => $statusCount(BugStatus::QaVerification),
            'in_progress' => $statusCount(BugStatus::InProgress),
            'reopened' => $statusCount(BugStatus::Reopened),
            'closed' => $statusCount(BugStatus::Closed),
            // FR-042: current assignee, excluding Closed bugs.
            'assigned_to_current_developer' => $assignedToCurrentDeveloper,
        ];
    }

    /**
     * Unset (null FK) is its own explicit bucket rather than being dropped
     * (FR-041: "Unset values ... MUST remain represented").
     *
     * @param  Builder<\App\Models\Bug>  $base
     * @return list<array<string, mixed>>
     */
    private function trackingBreakdown(Builder $base, string $column): array
    {
        $rows = (clone $base)
            ->leftJoin('tracking_values', "bugs.{$column}", '=', 'tracking_values.id')
            ->select(
                'tracking_values.id as tracking_value_id',
                'tracking_values.code as code',
                'tracking_values.name as name',
                DB::raw('count(*) as aggregate'),
            )
            ->groupBy('tracking_values.id', 'tracking_values.code', 'tracking_values.name')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => $row->tracking_value_id,
            'code' => $row->tracking_value_id === null ? null : $row->code,
            'name' => $row->tracking_value_id === null ? 'Unset' : $row->name,
            'count' => (int) $row->aggregate,
        ])->values()->all();
    }

    /**
     * @param  Builder<\App\Models\Bug>  $base
     * @return list<array<string, mixed>>
     */
    private function projectBreakdown(Builder $base): array
    {
        $rows = (clone $base)
            ->join('projects', 'projects.id', '=', 'bugs.project_id')
            ->select('projects.id as project_id', 'projects.key as key', 'projects.name as name', DB::raw('count(*) as aggregate'))
            ->groupBy('projects.id', 'projects.key', 'projects.name')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => $row->project_id,
            'key' => $row->key,
            'name' => $row->name,
            'count' => (int) $row->aggregate,
        ])->values()->all();
    }

    /**
     * Unassigned Bugs are their own explicit bucket, not dropped.
     *
     * @param  Builder<\App\Models\Bug>  $base
     * @return list<array<string, mixed>>
     */
    private function developerBreakdown(Builder $base): array
    {
        $rows = (clone $base)
            ->leftJoin('memberships', 'memberships.id', '=', 'bugs.assignee_membership_id')
            ->leftJoin('users', 'users.id', '=', 'memberships.user_id')
            ->select('users.id as user_id', 'users.display_name as display_name', DB::raw('count(*) as aggregate'))
            ->groupBy('users.id', 'users.display_name')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => $row->user_id,
            'display_name' => $row->user_id === null ? 'Unassigned' : $row->display_name,
            'count' => (int) $row->aggregate,
        ])->values()->all();
    }

    /**
     * FR-043/FR-044: elapsed creation-to-most-recent-resolution time for
     * currently Resolved/QA Verification/Closed bugs with a recorded
     * resolution event (their active ResolutionAttempt). Reopened bugs
     * clear `active_resolution_attempt_id`, so they are naturally excluded
     * until resolved again — no separate status check needed for that rule.
     * A-03: elapsed time is measured from Bug creation, so any prior
     * reopened dwell time before the latest resolution is included, not
     * subtracted. Zero qualifying samples report `null`, never `0` (FR-043).
     *
     * @param  Builder<\App\Models\Bug>  $base
     * @return array{sample_count: int, milliseconds: int|null, included_outcomes: list<string>}
     */
    private function resolutionTime(Builder $base): array
    {
        $qualifying = [BugStatus::Resolved->value, BugStatus::QaVerification->value, BugStatus::Closed->value];

        $elapsed = (clone $base)
            ->whereIn('bugs.status', $qualifying)
            ->whereNotNull('bugs.active_resolution_attempt_id')
            ->join('resolution_attempts', 'resolution_attempts.id', '=', 'bugs.active_resolution_attempt_id')
            ->select(DB::raw('EXTRACT(EPOCH FROM (resolution_attempts.recorded_at - bugs.created_at)) * 1000 as elapsed_ms'))
            ->pluck('elapsed_ms');

        $sampleCount = $elapsed->count();

        return [
            'sample_count' => $sampleCount,
            'milliseconds' => $sampleCount > 0 ? (int) round((float) $elapsed->avg()) : null,
            // Every outcome type qualifies: this measures elapsed time since
            // a disposition was recorded, not proof of a verified fix.
            'included_outcomes' => ['fixed', 'duplicate', 'cannot_reproduce', 'wont_fix'],
        ];
    }
}
