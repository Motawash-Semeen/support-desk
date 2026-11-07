<?php

namespace App\Http\Resources;

use App\Models\TicketActivity;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin TicketActivity
 *
 * Description only: old/new values can hold internal details and stay
 * server-side.
 */
class TicketActivityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'type' => $this->type->value,
            'description' => $this->description,
            'actor' => $this->actor?->name,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }
}
