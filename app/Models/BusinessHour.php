<?php

namespace App\Models;

use App\Observers\SlaRulesObserver;
use Database\Factories\BusinessHourFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Weekly working schedule. Times are wall-clock times in
 * config('support.timezone'); weekday uses Carbon numbering (0 = Sunday).
 */
#[ObservedBy(SlaRulesObserver::class)]
class BusinessHour extends Model
{
    /** @use HasFactory<BusinessHourFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'weekday',
        'is_working_day',
        'opens_at',
        'closes_at',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'is_working_day' => 'boolean',
        ];
    }
}
