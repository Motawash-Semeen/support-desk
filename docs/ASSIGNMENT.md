# Ticket assignment

Two ways a ticket gets an owner:

- **manually**: an agent accepts it from the queue, or an admin assigns or reassigns it;
- **automatically**: a queued job picks an agent when the ticket is created, and again every 5 minutes for
  tickets that are still unassigned.

Both paths end in the same **compare-and-set** write, so they cannot overwrite each other.

## Auto-assignment strategy

Configured with `SUPPORT_ASSIGNMENT_STRATEGY` (`config/support.php` → `assignment.strategy`). Strategies implement
`App\Services\Assignment\AssignmentStrategy` and are resolved from the container, so adding one is a single class
plus one config entry.

### 1. Candidate pool (`CandidateAgents`)

An agent is a candidate when:

- `role = agent` **and** `is_available = 1` (agents toggle availability themselves; unavailable agents get nothing);
- **and** they specialise in the ticket's category (`agent_category` pivot) **or** have no categories at all
  (generalists cover every category).

If nobody matches the category, the pool falls back to **all available agents**, so a niche category never leaves
a ticket stranded while people are free.

### 2. Ranking: `least_workload` (default, `LeastWorkloadStrategy`)

One SQL query ranks the pool:

| Order | Rule | Why |
|---|---|---|
| 1 | Lowest **weighted open workload** = Σ weight(priority) over the agent's active tickets (open, in progress, waiting); weights `critical 3, high 2, medium 1, low 1` (`assignment.workload_weights`) | Two critical tickets are more work than two low ones; finished tickets do not count |
| 2 | Never assigned before (`last_assigned_at IS NULL`) first | A new agent gets work immediately |
| 3 | Least recently assigned (`last_assigned_at` ascending) | Spreads tickets when workloads tie |
| 4 | Lowest user id | Total, deterministic order: the same state always picks the same agent |

The workload is a correlated subquery computed for all candidates in **one query** (served by
`tickets(assigned_agent_id, status)`), never one query per agent.

**Example:** with three available agents, six new tickets are spread 2-2-2
(`Feature/Assignment/AutoAssignmentTest`). An agent holding one critical ticket (weight 3) loses to an agent holding
two low tickets (weight 2) (`Unit/Assignment/StrategyOrderingTest`).

### Alternative: `round_robin` (`RoundRobinStrategy`)

Rules 2–4 only, ignoring workload. It is simpler and perfectly fair in count, but one agent can end up holding all
the critical tickets. It exists to show the strategy seam.

### 3. Assignment

The job then calls `ClaimTicket` (below). It sets `assigned_agent_id`, `assigned_at`, updates the agent's
`last_assigned_at`, moves `open` → `in_progress`, writes an `assigned` activity (actor = system) and dispatches
`TicketAssigned`. The agent and the customer are then notified.

### When nobody is available

The ticket stays in the **unassigned queue** (`/staff/unassigned`). One `auto_assign_skipped` activity is recorded
for each unassigned stretch, not one every 5 minutes. `tickets:assign-unassigned` re-queues unassigned active tickets
every 5 minutes, those due soonest first. An SLA breach on an unassigned ticket also triggers an immediate
auto-assignment attempt.

## Concurrency

The danger: two agents click **Accept** at the same moment, or an admin reassigns a ticket that the auto-assign job
or an agent is claiming at that instant.

### Compare-and-set instead of read-then-write

Every assignment write is a **single guarded `UPDATE`** whose WHERE clause states the expected current owner
(`app/Actions/Assignment/*`, `Concerns/GuardedAssignment`):

```sql
-- Accept / auto-assign (ClaimTicket)
UPDATE tickets SET assigned_agent_id = :agent, assigned_at = :now,
       status = CASE WHEN status = 'open' THEN 'in_progress' ELSE status END
 WHERE id = :id AND assigned_agent_id IS NULL AND status IN ('open','in_progress','waiting_for_customer');

-- Admin reassign (AssignTicket): the agent the admin SAW on the page
 ... WHERE id = :id AND assigned_agent_id <=> :expected_agent AND status IN (...active...);

-- Release (ReleaseTicket): only while the ticket still has the agent the releaser saw
 ... WHERE id = :id AND assigned_agent_id = :expected AND status IN (...active...);
```

The database evaluates the guard and the write atomically on the row. The **affected-row count** is the verdict:
1 means you won; 0 means somebody else changed the ticket first. A loser gets **409 Conflict** ("This ticket was just
taken by another agent", `AssignmentConflict`); a JSON client gets a JSON error. No lock is held across the request
and nothing can be overwritten silently.

### Locking the candidate pool

Auto-assignment must also avoid giving two simultaneous tickets to the same "least busy" agent because both jobs
read the same workload. Inside the job's transaction, the strategy runs `SELECT … FOR UPDATE` on the candidate agent
rows **in id order** before reading workload. Concurrent jobs therefore serialise on the agents, and a consistent
lock order means they cannot deadlock. The ticket row itself is still protected by the compare-and-set.

### Duplicate jobs

`AutoAssignTicket` is `ShouldBeUnique` per ticket id (`uniqueFor` 600 s), so the create hook and the scheduler retry
cannot queue the same ticket twice. If an agent accepts the ticket while the job runs, `ClaimTicket` affects 0 rows
and the job simply ends: the ticket has an owner, which was the goal.

### How it is proven

| Test | What it proves |
|---|---|
| `Feature/Assignment/ClaimTicketTest` "lets exactly one of two agents claim the same ticket (deterministic race)" | Both agents hold a stale copy; both run the guarded UPDATE; exactly one wins and the loser gets `false`. Deterministic on every run. |
| `ClaimTicketTest` "claims with ONE conditional UPDATE whose WHERE is the guard" | The guard is in SQL, not in PHP |
| `ClaimTicketTest` conflict tests | Admin reassign vs concurrent accept or reassign → 409, never clobbered; release never undoes a newer assignment |
| `Concurrency/ClaimTicketRaceTest` | 20 real PHP processes released at the same instant against MySQL: exactly one winner, 19 conflicts |
| `AutoAssignmentTest` "locks candidate agents in id order" | `FOR UPDATE` is issued in id order (MySQL) |
