<?php

namespace App\Actions\Administration\Concerns;

use App\Models\Bug;
use App\Models\TrackingValue;
use App\Models\User;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Shared remediation gate for TrackingValue deactivation (FR-007, FR-008):
 * a category/priority/severity/tag still referenced by an open Bug must be
 * replaced with another active same-project-and-kind value, or explicitly
 * unset, before the value can be deactivated. Never silently orphans a
 * classification.
 */
trait RemediatesBugClassifications
{
    /**
     * @param  array<string, mixed>  $remediation
     */
    protected function remediateClassification(User $actor, TrackingValue $trackingValue, array $remediation): void
    {
        $field = match ($trackingValue->kind->value) {
            'category' => 'category_id',
            'priority' => 'priority_id',
            'severity' => 'severity_id',
            default => null,
        };

        $affectedDirect = $field !== null
            ? Bug::open()->where($field, $trackingValue->id)->get()
            : collect();

        $affectedTags = $trackingValue->kind->value === 'tag'
            ? Bug::open()->whereHas('tags', fn ($q) => $q->where('tracking_values.id', $trackingValue->id))->get()
            : collect();

        $affected = $affectedDirect->merge($affectedTags)->unique('id');

        if ($affected->isEmpty()) {
            return;
        }

        $replacementId = $remediation['replace_with'] ?? null;
        $unset = ($remediation['unset'] ?? false) === true;

        if (! $unset && $replacementId === null) {
            throw new ConflictHttpException(sprintf(
                '%d open bug(s) still reference this value (%s); replace it or explicitly unset it first.',
                $affected->count(),
                $affected->pluck('public_id')->implode(', '),
            ));
        }

        $replacement = null;
        if ($replacementId !== null) {
            $replacement = TrackingValue::where('id', $replacementId)
                ->where('project_id', $trackingValue->project_id)
                ->where('kind', $trackingValue->kind->value)
                ->where('is_active', true)
                ->first();

            if ($replacement === null) {
                throw new ConflictHttpException('The replacement value must be an active value of the same project and kind.');
            }
        }

        foreach ($affected as $bug) {
            if ($field !== null) {
                $bug->{$field} = $replacement?->id;
                $bug->save();
            } else {
                $bug->tags()->detach($trackingValue->id);
                if ($replacement !== null) {
                    $bug->tags()->attach($replacement->id, ['added_by_id' => $actor->id, 'added_at' => now()]);
                }
            }
        }
    }
}
