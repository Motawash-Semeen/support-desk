# Actions/Sla

Admin changes to SLA rules: policies per priority, weekly business hours and holidays. Each Action saves
models one by one inside a transaction so `SlaRulesObserver` flushes the cached rules after commit. Existing
tickets keep their SLA snapshot; `php artisan sla:recalculate --open` re-applies rules to active tickets.
