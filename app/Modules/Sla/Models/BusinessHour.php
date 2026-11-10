<?php

namespace App\Modules\Sla\Models;

use App\Modules\Sla\SlaService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/** Weekly schedule: one row per weekday (0 = Sunday), times in the business timezone. */
class BusinessHour extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'weekday',
        'is_working_day',
        'opens_at',
        'closes_at',
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
            'weekday' => 'integer',
            'is_working_day' => 'boolean',
        ];
    }
}
