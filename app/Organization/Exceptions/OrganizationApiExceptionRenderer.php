<?php

namespace App\Organization\Exceptions;

use App\Models\User;
use App\Organization\Models\Organization;
use App\Organization\Models\OrganizationContract;
use App\Organization\Models\OrganizationDomain;
use App\Organization\Models\OrganizationInvitation;
use App\Organization\Models\OrganizationMembership;
use App\Organization\Models\OrganizationRedemptionCode;
use App\Organization\Models\OrganizationRoleAssignment;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Error responses for Organization API routes only.
 *
 * Registered before the application's shared handlers in bootstrap/app.php. Every callback returns null
 * unless the request was routed to an Organization controller, so other APIs keep their existing responses.
 * Responses use the existing { "message": ... } format. Exceptions are still reported (logged) as usual.
 */
final class OrganizationApiExceptionRenderer
{
    private const CONTROLLER_NAMESPACE = 'App\\Organization\\Http\\Controllers\\';

    private const SERVER_ERROR_MESSAGE = 'Unexpected server error. Please try again later.';

    // Postgres: invalid text representation, numeric value out of range (e.g. a malformed or oversized ID).
    private const INVALID_IDENTIFIER_SQLSTATES = ['22P02', '22003'];

    /** Readable names for "<name> not found." */
    private const MODEL_NAMES = [
        Organization::class => 'Organization',
        OrganizationContract::class => 'Contract',
        OrganizationDomain::class => 'Domain',
        OrganizationInvitation::class => 'Invitation',
        OrganizationMembership::class => 'Membership',
        OrganizationRedemptionCode::class => 'Redemption code',
        OrganizationRoleAssignment::class => 'Role assignment',
        User::class => 'User',
    ];

    public static function register(Exceptions $exceptions): void
    {
        // Laravel turns ModelNotFoundException into NotFoundHttpException before render callbacks run,
        // so the original exception is read from getPrevious().
        $exceptions->render(function (NotFoundHttpException $e, Request $request): ?JsonResponse {
            return self::handles($request) ? self::notFound($e) : null;
        });

        // Must be registered before QueryException, which it extends.
        $exceptions->render(function (UniqueConstraintViolationException $e, Request $request): ?JsonResponse {
            return self::handles($request)
                ? self::json('This record already exists.', 409)
                : null;
        });

        $exceptions->render(function (QueryException $e, Request $request): ?JsonResponse {
            if (! self::handles($request)) {
                return null;
            }

            if (in_array((string) $e->getCode(), self::INVALID_IDENTIFIER_SQLSTATES, true)) {
                return self::json('Resource not found.', 404);
            }

            return self::json(self::SERVER_ERROR_MESSAGE, 500);
        });

        // Anything without its own handler: a generic 500 that never exposes the exception message,
        // whatever APP_DEBUG is. Known HTTP, validation and authentication errors fall through to the
        // shared handlers, which already render them correctly.
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::handles($request)
                || $e instanceof HttpExceptionInterface
                || $e instanceof ValidationException
                || $e instanceof AuthenticationException
                || $e instanceof HttpResponseException) {
                return null;
            }

            return self::json(self::SERVER_ERROR_MESSAGE, 500);
        });
    }

    /**
     * True for API requests routed to an Organization controller. Requests that matched no route at all
     * are left to the shared handler ("Requested endpoint was not found.").
     */
    public static function handles(Request $request): bool
    {
        if (! $request->is('api/*')) {
            return false;
        }

        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        return str_starts_with((string) $route->getControllerClass(), self::CONTROLLER_NAMESPACE);
    }

    private static function notFound(NotFoundHttpException $e): JsonResponse
    {
        $previous = $e->getPrevious();

        if ($previous instanceof ModelNotFoundException) {
            $name = self::MODEL_NAMES[$previous->getModel()] ?? 'Resource';

            return self::json("{$name} not found.", 404);
        }

        $message = $e->getMessage();

        return self::json($message !== '' && $message !== 'Not found.' ? $message : 'Resource not found.', 404);
    }

    private static function json(string $message, int $status): JsonResponse
    {
        return response()->json(['message' => $message], $status);
    }
}
