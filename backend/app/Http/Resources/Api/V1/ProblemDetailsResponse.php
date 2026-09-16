<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProblemDetailsResponse
{
    /**
     * Build an RFC 9457 application/problem+json response.
     *
     * @param  array<string, mixed>  $errors
     */
    public static function make(
        Request $request,
        int $status,
        string $title,
        ?string $detail = null,
        string $type = 'about:blank',
        array $errors = [],
    ): JsonResponse {
        $payload = [
            'type' => $type,
            'title' => $title,
            'status' => $status,
            'detail' => $detail ?? $title,
            'instance' => $request->path(),
        ];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status, [
            'Content-Type' => 'application/problem+json',
        ]);
    }
}
