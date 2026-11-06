# Actions

One class per use case (e.g. `CreateTicket`, `AssignTicket`). An Action owns the
transaction boundary for its use case, dispatches domain events, and is the only
place a controller, job or command calls to change state. Actions expose a single
public `handle()` (or `__invoke()`) method and receive dependencies via the constructor.
