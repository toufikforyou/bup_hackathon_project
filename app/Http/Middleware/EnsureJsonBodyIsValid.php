<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureJsonBodyIsValid
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH'], true)) {
            return $next($request);
        }

        $body = $request->getContent();

        if (trim($body) === '') {
            return $this->malformed('The request body is empty.');
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->malformed('The request body is not valid JSON.');
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return $this->malformed('The request body must be a JSON object.');
        }

        return $next($request);
    }

    private function malformed(string $message): JsonResponse
    {
        return new JsonResponse([
            'error' => 'bad_request',
            'message' => $message,
        ], JsonResponse::HTTP_BAD_REQUEST);
    }
}
