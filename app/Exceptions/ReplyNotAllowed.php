<?php

namespace App\Exceptions;

use DomainException;
use Illuminate\Contracts\Debug\ShouldntReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * A reply that the ticket's state does not accept (closed tickets take no
 * public replies). Rendered as a validation error on `body`; a user error,
 * not reported.
 */
class ReplyNotAllowed extends DomainException implements ShouldntReport
{
    public static function ticketClosed(): self
    {
        return new self(__('This ticket is closed. Reopen it to reply.'));
    }

    public function render(Request $request): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $this->getMessage(), 'errors' => ['body' => [$this->getMessage()]]], 422);
        }

        return back()->withInput()->withErrors(['body' => $this->getMessage()]);
    }
}
