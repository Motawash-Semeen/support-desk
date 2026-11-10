<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Role check for whole route groups, e.g. role:admin,agent. Per-ticket rules are in the policies. */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new AuthenticationException;
        }

        // Fails loudly on a typo in a route.
        $allowed = array_map(fn (string $role) => Role::from(trim($role)), $roles);

        abort_unless(in_array($user->role, $allowed, true), Response::HTTP_FORBIDDEN);

        return $next($request);
    }
}
