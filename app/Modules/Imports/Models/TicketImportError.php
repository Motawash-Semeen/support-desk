<?php

namespace App\Modules\Imports\Models;

use Database\Factories\TicketImportErrorFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketImportError extends Model
{
    /** @use HasFactory<TicketImportErrorFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'ticket_import_id',
        'row_number',
        'raw_data',
        'errors',
    ];

    protected function casts(): array
    {
        return [
            'row_number' => 'integer',
            'raw_data' => 'array',
            'errors' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<TicketImport, $this> */
    public function import(): BelongsTo
    {
        return $this->belongsTo(TicketImport::class, 'ticket_import_id');
    }
}
