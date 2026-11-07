<?php

namespace App\Modules\Sla\Models;

use App\Modules\Sla\Observers\SlaRulesObserver;
use Database\Factories\HolidayFactory;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
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
}
