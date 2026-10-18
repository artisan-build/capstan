<?php

namespace App\Http\Responses;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

final class BrandedErrorResponse
{
    public function render(Throwable $exception, Request $request): ?Response
    {
        if ($request->expectsJson()
            || ! $request->acceptsHtml()
            || $this->isRawResponse($request)
            || $exception instanceof AuthenticationException
            || $exception instanceof ValidationException
            || $exception instanceof HttpResponseException) {
            return null;
        }

        $status = $exception instanceof HttpExceptionInterface ? $exception->getStatusCode() : 500;

        if (! in_array($status, [403, 404, 419, 500], true)) {
            return null;
        }

        $headers = $exception instanceof HttpExceptionInterface ? $exception->getHeaders() : [];

        return response()->view('errors.app', [
            'status' => $status,
            'title' => match ($status) {
                403 => 'Forbidden',
                404 => 'Not Found',
                419 => 'Page Expired',
                default => 'Server Error',
            },
        ], $status, $headers);
    }

    private function isRawResponse(Request $request): bool
    {
        return $request->is(
            'api/*',
            'mcp',
            'mcp/*',
            'up',
            'storage/*',
            'flux/*',
            'livewire-*',
            'bfc-client',
            'bfc/assets/*',
            'bfc/meta',
            'bfc/client-observations',
            'bfc/console/*',
            'bfc/credentials',
            'bfc/installation/credentials',
            'bfc/me/credentials',
            'artifacts/*/content',
            'brand-assets/*',
            'brand-previews/*',
            'page-shares/*/content',
            'pages/*/content',
            'pages/*/export',
        );
    }
}
