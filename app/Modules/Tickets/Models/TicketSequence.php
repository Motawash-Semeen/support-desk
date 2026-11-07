<?php

namespace App\Modules\Tickets\Models;

use Database\Factories\TicketSequenceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-year ticket number counter. Read and incremented only inside a
 * transaction with a row lock (see the ticket number generator).
 */
class TicketSequence extends Model
{
    /** @use HasFactory<TicketSequenceFactory> */
    use HasFactory;

    protected $primaryKey = 'year';

    public $incrementing = false;

    protected $keyType = 'int';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'year',
        'last_number',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'last_number' => 'integer',
        ];
    }
}
