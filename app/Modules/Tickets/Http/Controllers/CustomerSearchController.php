<?php

namespace App\Modules\Tickets\Http\Controllers;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Tickets\Http\Requests\CustomerSearchRequest;
use App\Modules\Tickets\Support\FullTextQuery;
use Illuminate\Http\JsonResponse;

/**
 * Autocomplete for the ticket list's customer filter. Prefix matches only
 * (name LIKE 'q%' via users(role, name), email LIKE 'q%' via the unique email
 * index), at most 10 rows, id/name/email only. The list then filters by the
 * chosen id, so the main ticket query never joins users on free text.
 */
class CustomerSearchController extends Controller
{
    public function __invoke(CustomerSearchRequest $request): JsonResponse
    {
        $this->authorize('search-customers');

        $prefix = FullTextQuery::escapeLike(trim($request->validated('q'))).'%';

        $customers = User::query()
            ->where('role', Role::Customer)
            ->where(fn ($query) => $query->where('name', 'like', $prefix)->orWhere('email', 'like', $prefix))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'email']);

        return response()->json($customers->map->only(['id', 'name', 'email'])->values());
    }
}
