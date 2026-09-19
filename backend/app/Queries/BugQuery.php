<?php

namespace App\Queries;

use App\Models\Membership;
use App\Models\Project;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Permission-first Bug discovery (FR-035–FR-038). Always starts from
 * VisibleBugs — never searches globally and filters afterward — then layers
 * exact public-ID lookup, case-insensitive text search, intersecting filter
 * dimensions (OR within each dimension), and stable sorting. Shared by the
 * list endpoint and DashboardQuery so both read the same matching Bug set.
 */
class BugQuery
{
    /**
     * @return Builder<\App\Models\Bug>
     */
    public function forUser(User $user): Builder
    {
        return (new VisibleBugs())->forUser($user);
    }

    /**
     * @param  Builder<\App\Models\Bug>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<\App\Models\Bug>
     */
    public function applyFilters(Builder $query, array $filters): Builder
    {
        $this->applySearch($query, $filters['q'] ?? null);
        $this->applyProjectFilter($query, $filters['project'] ?? []);
        $this->applyInFilter($query, 'status', $filters['status'] ?? []);
        $this->applyInFilter($query, 'severity_id', $filters['severity'] ?? []);
        $this->applyInFilter($query, 'priority_id', $filters['priority'] ?? []);
        $this->applyInFilter($query, 'category_id', $filters['category'] ?? []);
        $this->applyInFilter($query, 'reporter_id', $filters['reporter'] ?? []);
        $this->applyAssigneeFilter($query, $filters['assignee'] ?? []);
        $this->applyTagFilter($query, $filters['tag'] ?? []);
        $this->applyDateRange($query, 'created_at', $filters['created_from'] ?? null, $filters['created_to'] ?? null);
        $this->applyDateRange($query, 'updated_at', $filters['updated_from'] ?? null, $filters['updated_to'] ?? null);

        return $query;
    }

    /**
     * Every sort carries an ascending public-number tie-breaker so
     * pagination is deterministic even when the primary field repeats
     * (FR-038).
     *
     * @param  Builder<\App\Models\Bug>  $query
     * @return Builder<\App\Models\Bug>
     */
    public function applySort(Builder $query, string $sort, string $direction): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return match ($sort) {
            'created_at' => $query->orderBy('bugs.created_at', $direction)->orderBy('bugs.public_number', 'asc'),
            'priority' => $this->applyRankSort($query, 'priority_id', $direction),
            'severity' => $this->applyRankSort($query, 'severity_id', $direction),
            'public_id' => $query->orderBy('bugs.public_number', $direction),
            default => $query->orderBy('bugs.updated_at', $direction)->orderBy('bugs.public_number', 'asc'),
        };
    }

    /**
     * `q` is either an exact human-facing key (`BUG-000123`, case-insensitive)
     * or free text matched case-insensitively against title/description
     * (FR-035, A-04). An exact-key match never falls through to text search.
     *
     * @param  Builder<\App\Models\Bug>  $query
     */
    private function applySearch(Builder $query, ?string $q): void
    {
        $q = trim((string) $q);

        if ($q === '') {
            return;
        }

        if (preg_match('/^BUG-(\d+)$/i', $q, $matches)) {
            $query->where('bugs.public_number', (int) $matches[1]);

            return;
        }

        $escaped = addcslashes($q, '%_\\');
        $like = "%{$escaped}%";

        $query->where(function (Builder $sub) use ($like) {
            $sub->where('bugs.title', 'ilike', $like)
                ->orWhere('bugs.description', 'ilike', $like);
        });
    }

    /**
     * `project[]` carries project keys, matching the human-facing identifier
     * used everywhere else in the API (not raw UUIDs).
     *
     * @param  Builder<\App\Models\Bug>  $query
     * @param  array<int, string>  $keys
     */
    private function applyProjectFilter(Builder $query, array $keys): void
    {
        $keys = array_values(array_filter($keys, fn ($key) => is_string($key) && $key !== ''));

        if ($keys === []) {
            return;
        }

        $query->whereIn(
            'bugs.project_id',
            Project::query()->whereIn('key', array_map('strtoupper', $keys))->select('id'),
        );
    }

    /**
     * @param  Builder<\App\Models\Bug>  $query
     * @param  array<int, string>  $values
     */
    private function applyInFilter(Builder $query, string $column, array $values): void
    {
        $values = array_values(array_filter($values, fn ($value) => is_string($value) && $value !== ''));

        if ($values === []) {
            return;
        }

        $query->whereIn("bugs.{$column}", $values);
    }

    /**
     * `assignee[]` carries User UUIDs plus the literal `unassigned`; both
     * kinds present in the same request combine with OR, matching the
     * general within-dimension rule (FR-037).
     *
     * @param  Builder<\App\Models\Bug>  $query
     * @param  array<int, string>  $values
     */
    private function applyAssigneeFilter(Builder $query, array $values): void
    {
        $values = array_values(array_filter($values, fn ($value) => is_string($value) && $value !== ''));

        if ($values === []) {
            return;
        }

        $wantsUnassigned = in_array('unassigned', $values, true);
        $userIds = array_values(array_filter($values, fn ($value) => $value !== 'unassigned'));

        $query->where(function (Builder $sub) use ($userIds, $wantsUnassigned) {
            if ($userIds !== []) {
                $sub->orWhereIn(
                    'bugs.assignee_membership_id',
                    Membership::query()->whereIn('user_id', $userIds)->select('id'),
                );
            }

            if ($wantsUnassigned) {
                $sub->orWhereNull('bugs.assignee_membership_id');
            }
        });
    }

    /**
     * Matched via a `whereIn` subquery rather than a join, so selecting
     * multiple tags (OR within the dimension) can never multiply result
     * rows.
     *
     * @param  Builder<\App\Models\Bug>  $query
     * @param  array<int, string>  $tagIds
     */
    private function applyTagFilter(Builder $query, array $tagIds): void
    {
        $tagIds = array_values(array_filter($tagIds, fn ($value) => is_string($value) && $value !== ''));

        if ($tagIds === []) {
            return;
        }

        $query->whereIn(
            'bugs.id',
            DB::table('bug_tags')->whereIn('tracking_value_id', $tagIds)->select('bug_id'),
        );
    }

    /**
     * @param  Builder<\App\Models\Bug>  $query
     */
    private function applyDateRange(Builder $query, string $column, ?string $from, ?string $to): void
    {
        if ($from !== null && $from !== '') {
            $query->where("bugs.{$column}", '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->where("bugs.{$column}", '<=', $to);
        }
    }

    /**
     * Priority/severity sort by each TrackingValue's configured rank.
     * Unset (null FK, or the value has no rank) always sorts last,
     * regardless of direction — Postgres's default NULL ordering flips with
     * direction, so it is made explicit here instead (FR-038).
     *
     * @param  Builder<\App\Models\Bug>  $query
     * @return Builder<\App\Models\Bug>
     */
    private function applyRankSort(Builder $query, string $column, string $direction): Builder
    {
        $alias = "rank_{$column}";

        $query->leftJoin("tracking_values as {$alias}", function (JoinClause $join) use ($alias, $column) {
            $join->on("bugs.{$column}", '=', "{$alias}.id");
        })
            ->select('bugs.*')
            ->orderByRaw("({$alias}.rank IS NULL) asc")
            ->orderBy("{$alias}.rank", $direction)
            ->orderBy('bugs.public_number', 'asc');

        return $query;
    }
}
