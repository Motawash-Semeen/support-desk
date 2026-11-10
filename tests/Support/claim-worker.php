<?php

use App\Models\User;
use App\Modules\Assignment\AssignmentService;
use App\Modules\Tickets\Models\Ticket;
use Illuminate\Contracts\Console\Kernel;

/*
| Worker for tests/Concurrency/ClaimTicketRaceTest.php.
|
|   php tests/Support/claim-worker.php <ticketId> <agentId> <startAtUnixFloat>
|
| Boots the app against the database given in the environment, waits until
| the shared start time so all workers fire together, calls AssignmentService::claim() once
| and prints WON or LOST.
*/

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $ticketId, $agentId, $startAt] = $argv;

$ticket = Ticket::query()->findOrFail((int) $ticketId);
$agent = User::query()->findOrFail((int) $agentId);

while (microtime(true) < (float) $startAt) {
    usleep(500);
}

echo $app->make(AssignmentService::class)->claim($ticket, $agent, $agent) ? 'WON' : 'LOST';
