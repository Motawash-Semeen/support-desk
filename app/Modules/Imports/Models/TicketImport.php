<?php

namespace App\Modules\Imports\Models;

use App\Models\User;
use App\Modules\Imports\Enums\ImportStatus;
use Database\Factories\TicketImportFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TicketImport extends Model
{
    /** @use HasFactory<TicketImportFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'original_name',
        'path',
        'status',
        'total_rows',
        'processed_rows',
        'success_rows',
        'failed_rows',
        'last_processed_row',
        'error_message',
        'started_at',
        'finished_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'pending',
        'total_rows' => 0,
        'processed_rows' => 0,
        'success_rows' => 0,
        'failed_rows' => 0,
        'last_processed_row' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'total_rows' => 'integer',
            'processed_rows' => 'integer',
            'success_rows' => 'integer',
            'failed_rows' => 'integer',
            'last_processed_row' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Per-row validation failures. Named rowErrors to avoid confusion with
     * validation error bags.
     *
     * @return HasMany<TicketImportError, $this>
     */
    public function rowErrors(): HasMany
    {
        return $this->hasMany(TicketImportError::class);
    }
}
