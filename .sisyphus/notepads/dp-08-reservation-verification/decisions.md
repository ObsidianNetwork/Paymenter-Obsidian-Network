## 2026-04-23

- Kept dp-08 idempotency schema scoped to `idempotency_key` plus active-state uniqueness. Did not add a request-fingerprint column because that would expand the approved migration/API scope beyond the plan.
- Rejected CodeRabbit's expired-token exclusion nitpick: expired holds are already filtered out of pending-capacity math before token exclusion is applied, so there is no separate stale-exclusion branch to harden.
