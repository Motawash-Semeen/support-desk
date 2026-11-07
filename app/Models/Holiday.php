<?php

namespace App\Models;

use App\Observers\SlaRulesObserver;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A non-working calendar date in the business timezone.
 */
#[ObservedBy(SlaRulesObserver::class)]
class Holiday extends Model
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'date',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d',
        ];
    }

    /** Served by the unique index on date. */
    public function scopeBetweenDates(Builder $query, string $from, string $to): void
    {
        $query->whereBetween('date', [$from, $to]);
    }
}
