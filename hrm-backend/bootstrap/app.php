<?php

use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/V11',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Everything under /api returns the app's envelope: {result, message, data, error}
        $json = fn (Request $r) => $r->is('api/*') || $r->expectsJson();

        $exceptions->shouldRenderJsonWhen(fn (Request $r) => $json($r));

        $exceptions->render(function (ValidationException $e, Request $r) use ($json) {
            if ($json($r)) {
                return response()->json(['result' => false, 'message' => $e->validator->errors()->first(), 'data' => $r->is('api/V11/login') ? null : new \stdClass, 'error' => $e->errors()], 422);
            }
        });
        $exceptions->render(function (AuthenticationException $e, Request $r) use ($json) {
            if ($json($r)) {
                return response()->json(['result' => false, 'message' => 'Unauthenticated. Please log in again.', 'data' => null, 'error' => null], 401);
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, Request $r) use ($json) {
            if ($json($r)) {
                $msg = $e->getPrevious() instanceof ModelNotFoundException ? 'Record not found' : 'Endpoint not found';

                return response()->json(['result' => false, 'message' => $msg, 'data' => null, 'error' => null], 404);
            }
        });
        $exceptions->render(function (HttpExceptionInterface $e, Request $r) use ($json) {
            if ($json($r)) {
                return response()->json(['result' => false, 'message' => $e->getMessage() ?: 'Request failed', 'data' => null, 'error' => null], $e->getStatusCode());
            }
        });
        $exceptions->render(function (\Throwable $e, Request $r) use ($json) {
            if ($json($r) && ! config('app.debug')) {
                report($e);

                return response()->json(['result' => false, 'message' => 'Something went wrong. Please try again.', 'data' => null, 'error' => null], 500);
            }
        });
    })->create();
