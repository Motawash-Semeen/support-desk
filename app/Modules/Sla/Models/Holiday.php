<?php

namespace App\Modules\Sla\Models;

use App\Modules\Sla\SlaService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A non-working calendar date in the business timezone.
 */
class Holiday extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'date',
        'name',
    ];

    /** A change clears the SLA rule cache after the commit. */
    protected static function booted(): void
    {
        static::saved(fn () => SlaService::forgetRulesAfterCommit());
        static::deleted(fn () => SlaService::forgetRulesAfterCommit());
    }

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }
}
