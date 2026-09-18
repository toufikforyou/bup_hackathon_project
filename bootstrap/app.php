<?php

use App\Http\Middleware\EnsureJsonBodyIsValid;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

$isApiRequest = static fn (Request $request): bool => $request->expectsJson()
    || $request->is('health', 'optimize-energy', 'api/*');

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(
            at: env('TRUSTED_PROXIES', '*'),
            headers: Request::HEADER_X_FORWARDED_FOR
                | Request::HEADER_X_FORWARDED_HOST
                | Request::HEADER_X_FORWARDED_PORT
                | Request::HEADER_X_FORWARDED_PROTO
                | Request::HEADER_X_FORWARDED_AWS_ELB,
        );

        $middleware->api(prepend: [
            EnsureJsonBodyIsValid::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) use ($isApiRequest): void {
        $exceptions->shouldRenderJsonWhen($isApiRequest);

        $exceptions->render(function (Throwable $exception, Request $request) use ($isApiRequest): ?JsonResponse {
            if (! $isApiRequest($request)) {
                return null;
            }

            if ($exception instanceof HttpExceptionInterface) {
                $status = $exception->getStatusCode();

                return new JsonResponse([
                    'error' => $status === 404 ? 'not_found' : 'request_rejected',
                    'message' => $status === 404
                        ? 'Unknown endpoint.'
                        : 'The request could not be processed.',
                ], $status);
            }

            return new JsonResponse([
                'error' => 'internal_error',
                'message' => 'The service could not complete this request.',
            ], JsonResponse::HTTP_INTERNAL_SERVER_ERROR);
        });
    })->create();
