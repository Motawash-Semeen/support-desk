<?php

namespace App\Services\Sla;

/**
 * The rule in force for one priority. $id is null when no active policy row
 * exists and the config/support.php default is used.
 */
final readonly class SlaPolicyRule
{
    public function __construct(
        public ?int $id,
        public int $minutes,
        public float $warningThreshold,
    ) {}
}
