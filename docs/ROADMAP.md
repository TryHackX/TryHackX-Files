# TryHackX Files roadmap

**Updated:** 2026-08-25 for version 2.79.1

This file contains only work that remains open. Completed and verified work is removed; released
changes belong in [CHANGELOG.md](../CHANGELOG.md).

**Priority:** 🔴 critical · 🟠 high · 🟡 medium · 🟢 low · ⛔ externally blocked

---

## Owner roadmap — remaining items

This section preserves the owner's open ideas and decisions. The Codex audit section begins at
the explicit marker below.

### ⛔ Przelewy24 merchant sandbox validation

The built-in client and application flow are implemented and covered by mocked protocol tests.
The implementation follows official REST OpenAPI 1.0.17: Basic authentication, SHA-384
signatures, server-side registration, signed transaction callback, mandatory transaction
verification, status reconciliation, and asynchronous signed refund results.

The only remaining task requires real merchant credentials: `merchantId`, `posId`, CRC key and
REST API key. It cannot be completed honestly without access to a P24 merchant sandbox.

When credentials become available:

1. run `GET /api/v1/testAccess` from the administration panel or a controlled diagnostic;
2. execute the sandbox checklist in [P24.md](P24.md), including success, duplicate callback,
   invalid signature/amount, callback loss with polling, accepted refund, rejected refund and
   duplicate refund callback;
3. compare the implementation to the current P24 specification again before enabling production;
4. preserve sanitized evidence of the successful sandbox run.

Do not enable production payments until this checklist passes.

### 🟢 S3/MinIO storage, then Google Drive — intentionally deferred

Local storage remains the only supported backend. Introducing an untested abstraction in the
upload/download path before release would increase data-loss risk. Reopen this item only when
there is a concrete deployment, a disposable MinIO/S3 environment and, later, Google OAuth
credentials.

Recommended order:

1. define a `put/open/stream/delete/size/exists` storage interface and move the current local
   implementation behind it without changing behavior;
2. add S3/MinIO, compatibility tests, an existing-file migrator and a rollback procedure;
3. add Google Drive only after S3 is stable, including OAuth refresh and API quota handling.

Every backend must cover upload, Range requests, throttling, collection ZIPs, thumbnails,
delete queue, integrity sweep, backup/restore and quarantine. See [STORAGE.md](STORAGE.md).

**Closed owner decisions:** guest collections are out of scope; translations are uploaded as
validated JSON and will not gain an in-panel editor.

---

<!-- BEGIN CODEX AUDIT 2026-07-26 -->

## Codex audit addendum — open items only

### ⛔ AUD-OPEN-02 — first real Docker end-to-end run

The `docker-smoke` job statically exists and covers a clean schema, Apache and `/ready`,
database/storage integrity, upload/download, forwarded client IP, concurrent-download caps,
aborted full and Range responses, process termination, lease recovery and an idempotent reaper.

Docker is intentionally unavailable on the owner's Windows machine. No local Docker installation
is requested, so image construction, volume permissions and the full container transfer path
remain externally unverified.

Close this item by running `docker-smoke` in GitHub Actions or on an isolated Docker host twice
without weakening its assertions, then record the result in [DOCKER.md](DOCKER.md).

### Future scaling decisions

Infrastructure scaling is not an open task for this release. Add Redis, additional workers or a
new queue only after production telemetry identifies a real bottleneck (p95/p99 latency,
database-pool saturation, CPU, disk wait or queue age). The durable e-mail outbox and transfer
server are already separated.

### Definition of done

New roadmap work requires a reproducing test, concurrency tests for lease/reservation protocols,
forward-repair for schema changes, secret-free telemetry and updated documentation. Destructive
operations additionally require preview, audit logging and a tested backup/restore path.

<!-- END CODEX AUDIT 2026-07-26 -->
