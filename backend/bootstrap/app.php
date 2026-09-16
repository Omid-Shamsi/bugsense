<?php

use App\Http\Resources\Api\V1\ProblemDetailsResponse;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Matches the surface documented in config/cors.php: these routes always speak
        // problem+json, whether or not the client sends an explicit Accept header.
        $wantsProblem = fn ($request) => $request->is('api/*')
            || $request->is('login')
            || $request->is('logout')
            || $request->is('sanctum/csrf-cookie')
            || $request->expectsJson();

        $exceptions->shouldRenderJsonWhen($wantsProblem);

        // Laravel's own Handler::prepareException() converts several exception types
        // (TokenMismatchException, AuthorizationException, ModelNotFoundException, ...)
        // into a plain Symfony HttpException *before* any custom render() closure below
        // ever sees them. A closure type-hinted on the original class never fires for
        // those; only AuthenticationException and ValidationException reach us unconverted.
        $exceptions->render(function (AuthenticationException $e, $request) use ($wantsProblem) {
            if (! $wantsProblem($request)) {
                return null;
            }

            return ProblemDetailsResponse::make($request, 401, 'Unauthenticated', 'A valid session is required.');
        });

        $exceptions->render(function (ValidationException $e, $request) use ($wantsProblem) {
            if (! $wantsProblem($request)) {
                return null;
            }

            return ProblemDetailsResponse::make(
                $request,
                422,
                'Unprocessable Entity',
                'The given data was invalid.',
                errors: $e->errors(),
            );
        });

        $exceptions->render(function (HttpExceptionInterface $e, $request) use ($wantsProblem) {
            if (! $wantsProblem($request)) {
                return null;
            }

            $status = $e->getStatusCode();

            $titles = [
                401 => 'Unauthenticated',
                403 => 'Forbidden',
                404 => 'Not Found',
                409 => 'Conflict',
                413 => 'Payload Too Large',
                415 => 'Unsupported Media Type',
                419 => 'CSRF Token Mismatch',
                422 => 'Unprocessable Entity',
            ];

            return ProblemDetailsResponse::make(
                $request,
                $status,
                $titles[$status] ?? ($e->getMessage() !== '' ? $e->getMessage() : 'Error'),
            );
        });

        // Safe catch-all: any exception type not handled above (a bug, a DB failure, a
        // TypeError, ...) must still return a generic problem+json response on the API
        // surface instead of leaking Laravel's debug page (stack trace, file, line).
        $exceptions->render(function (\Throwable $e, $request) use ($wantsProblem) {
            if (! $wantsProblem($request)) {
                return null;
            }

            return ProblemDetailsResponse::make(
                $request,
                500,
                'Server Error',
                'An unexpected error occurred.',
            );
        });
    })->create();
