<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Coarse, role-only gate for whole route groups, e.g. `role:admin,agent`.
 * It knows nothing about individual records: per-ticket rules live in
 * policies, which every controller action still calls.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        // Role::from() fails loudly on a typo in a route definition.
        $allowed = array_map(fn (string $role) => Role::from(trim($role)), $roles);

        abort_unless(in_array($user->role, $allowed, true), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
