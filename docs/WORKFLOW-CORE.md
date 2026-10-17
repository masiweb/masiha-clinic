# Visit workflow core — phase 2

Based on `main` commit `ec35607f4fc7ceb201fe38f5ce37e935920730aa`.
The deployment audited on 2026-10-04 contains files from several commits;
upgrade from a coherent checkout, not by copying individual changed files.

## Data and compatibility

`deploy/workflow.sql` adds `visit_workflows` and `visit_events`, keyed to existing
`physio_sessions`. Reapplying it does not duplicate snapshots or overwrite states.
It does not rewrite patients, sessions, imports, payments, inventory, room or
insurance. Imported historical visits stay in their existing `import_*` tables.
Native historical sessions receive a `legacy_snapshot` at migration time;
this is not the original clinical event time. Unknown actual timestamps remain NULL.

Workflow states: scheduled, referred, waiting, in_service, visited, discharged,
absent and cancelled. The existing `status` and `turn_state` fields remain compatible
with reports, session counts, booking conflicts and the current turn board.
The queue's old `done` command now means discharge, after a clinical result exists.
Queue operators cannot mark clinical treatment complete by changing queue state.

## Transactions and permissions

Every new booking records its initial event inside the booking transaction.
`workflowTransition()` locks the session and workflow row, checks patient scope,
validates the transition, updates legacy fields, records observed timestamps,
appends the event, and writes the existing audit record in one transaction.
`workflow_version` on queue and result forms rejects submissions from stale pages.
Repeated state changes are no-ops. Events have no application update/delete API.

The assigned treating clinician alone can record/correct clinical results;
an admin who is not that appointment's clinician is also rejected.
The existing visit-form scope must still permit access. Assigned clinicians can
access a session created by reception. Reception needs appointment permission
and patient scope to manage the queue, but cannot enter clinical results.
Patients can cancel only their own future scheduled appointment before check-in.
Clinical corrections retain before/after values and preserve actual timestamps.
Corrections after discharge do not reopen the visit.

Actual arrival, call, treatment start/end and departure are recorded when observed.
Direct historical result entry does not imply an observed arrival or treatment start.
An imported visit never silently becomes a new operational appointment.

## Timeline contract

Authenticated staff with appropriate appointment/episode and patient access:

`GET /api/visit-timeline?session_id=123`

Returns workflow state/version/timestamps, ordered events, and waiting/treatment
duration in seconds when both actual timestamps are known (otherwise NULL).
Clinical before/after details are filtered under visit-form scope.
It is read-only and does not create a snapshot or mutate the visit.

Staff POST actions use existing CSRF protection:

- `visit_state`: session_id, state, workflow_version (optional for API compatibility), reason.
- `turn_state`: existing queue form, now using workflow transitions.
- `session`: existing clinical form, now recording clinical events atomically.

The complete Appointment Dashboard is phase 3; phase 2 does not redesign it.
Reopening a terminal cancelled/absent visit is deliberately not available;
book a new appointment. A future audited correction action can add this safely.

## Verification and deployment

Run on a disposable test environment with PHP 8.3 and MariaDB:

```bash
python3 tests/workflow.py
python3 tests/importer.py
python3 tests/deploy-upgrade.py
find app public deploy importer -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

Tests create/drop randomly named databases and never use `masiha_clinic`.
`MASIHA_TEST_DB_HOST` optionally selects a TCP host for isolated container tests;
the default is localhost. CI runs both regression suites.

Before deployment, confirm the target host and paused import/SMS policy,
verify the existing full backup and code snapshot, make a fresh backup,
and record counts for patients, imports, native sessions, finance and inventory.
Use a checkout pinned to the tested commit and the existing `deploy/upgrade.sh`.
This applies the additive migration before copying the coherent release.
The upgrade requires a clean, separate Git checkout. It pauses only active timers,
refuses an active worker, takes the full backup and a code snapshot, and keeps PHP
stopped while applying migrations and copying files. It restores previous activity
states on success/failure, never enables a disabled timer, and restores old code if
copying fails. A failed additive migration is not automatically undone in the DB.
Check health, rendered pages, hashes of all deployed tracked files and the same
data counts after deployment. Resume only timers that were already active.
Do not restore a pre-upgrade DB after new clinic activity without reconciling it.
For code-only rollback the new additive tables can remain; retain their event history.
