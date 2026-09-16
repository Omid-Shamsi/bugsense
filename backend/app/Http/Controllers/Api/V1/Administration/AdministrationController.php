<?php

namespace App\Http\Controllers\Api\V1\Administration;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ProblemDetailsResponse;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

abstract class AdministrationController extends Controller
{
    /**
     * Referenced administrative records (User, Project, Membership,
     * MembershipRole, TrackingValue) are never hard-deleted, only
     * deactivated. Every admin resource exposes this as its DELETE
     * handler instead of a 405 or a silent no-op.
     */
    protected function rejectHardDeletion(Request $request): JsonResponse
    {
        return ProblemDetailsResponse::make(
            $request,
            409,
            'Conflict',
            'Hard deletion is not supported for this resource; deactivate it instead.',
        );
    }

    /**
     * Some invariants (one membership per project/user, one active
     * priority/severity rank per project) are only enforceable as database
     * constraints. Surface a violation as 409 Conflict instead of a raw
     * 500 from an uncaught QueryException.
     */
    protected function runOrConflict(callable $callback, string $message)
    {
        try {
            return $callback();
        } catch (UniqueConstraintViolationException $e) {
            throw new ConflictHttpException($message, $e);
        }
    }
}
