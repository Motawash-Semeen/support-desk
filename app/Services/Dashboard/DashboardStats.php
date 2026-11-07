<?php

namespace App\Services\Dashboard;

/**
 * Plain, cacheable snapshot of the staff dashboard (scalars and arrays only,
 * no models). `scope` is 'global' for admins and 'agent' for agents (their
 * own tickets plus the unassigned queue).
 */
final readonly class DashboardStats
{
    /**
     * @param  array<string, int>  $byPriority  priority value => active tickets (every priority present)
     * @param  list<array{id: int, name: string, active: int, breached: int}>  $byAgent  top 10 by active tickets
     * @param  list<array{ticket_number: string, subject: string, agent: ?string, description: string, at: string}>  $recentEscalations
     */
    public function __construct(
        public string $scope,
        public int $totalActive,
        public int $unassignedActive,
        public int $criticalActive,
        public int $slaBreachedActive,
        public int $approachingActive,
        public int $resolvedTotal,
        public int $resolvedToday,
        public int $escalatedActive,
        public array $byPriority,
        public array $byAgent,
        public array $recentEscalations,
        public string $generatedAt,
    ) {}
}
