# Actions/Tickets

Ticket lifecycle use cases: create, update, change status/priority, close, reopen.
Each wraps its writes in `DB::transaction` and records ticket activity.
