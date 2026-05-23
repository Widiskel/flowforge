# Code Review Log

Catatan review per PR yang sudah masuk ke `main`.

## PR #1 — feat(auth): JWT authentication with refresh token rotation

- Branch: `feature/auth-jwt-rbac`
- PR: https://github.com/Widiskel/flowforge/pull/1
- Status: merged

Catatan review:
- `RotateJwtRefreshTokenAction` masih duplikasi logic dengan `JwtRefreshTokenStore::rotate()`. Pilih satu jadi single source supaya ga pecah.
- `AuthController::logout` catch `Throwable` terlalu lebar, lebih aman dipersempit ke `JWTException`.

Tindak lanjut:
- `JwtRefreshTokenStore::rotate()` dihapus. Rotation + reuse-detection cukup di `RotateJwtRefreshTokenAction`.
- `AuthController::logout` sekarang catch `JWTException` saja.

Verifikasi setelah fix: 17 tests pass, pint dan typecheck hijau, build sukses.

Putusan: OK merge.

## PR #2 — feat(workflow): CRUD and versioning

- Branch: `feature/workflow-crud-versioning`
- PR: https://github.com/Widiskel/flowforge/pull/2
- Status: merged

Catatan review:
- `per_page > 100` di `WorkflowController::index` dan `WorkflowVersionController::index` pakai `abort_if(..., 422)`. Endpoint lain pakai FormRequest yang return shape `{message, errors[]}` standar Laravel, jadi yang ini inkonsisten dari sisi API consumer.
- `current_version_id` di-set lewat `forceFill(...)->save()` padahal kolomnya sudah `$fillable`. Misleading, seolah-olah lagi bypass mass assignment guard. Cukup pakai `update(...)`.

Tindak lanjut:
- `per_page` sekarang divalidasi via `validator()` jadi shape error JSON-nya konsisten.
- `forceFill(...)->save()` diganti `update(...)` di Create/Update/Rollback action.

Verifikasi setelah fix: 35 tests pass (105 assertions), pint dan typecheck hijau, build sukses.

Putusan: OK merge.

## PR #3 — feat(engine): DAG validator, planner, executor

- Branch: `feature/dag-validator-executor`
- PR: https://github.com/Widiskel/flowforge/pull/3
- Status: merged

Catatan review:
- `HttpStepHandler` catch `Throwable` terlalu lebar. Seharusnya catch `ConnectionException` aja supaya bug lain yang nggak terkait HTTP call nggak ke-mask.
- `WorkflowExecutor::executeStepWithRetry` hard-cap delay 10s. Exponential backoff jadi nggak beneran exponential kalau cap-nya lebih kecil dari calculated delay. Mending pakai `maxDelayMs` dari config step.

Tindak lanjut:
- `HttpStepHandler` sekarang catch `ConnectionException`.
- Retry delay sekarang pakai `maxDelayMs` dari config step, bukan hard-cap.

Verifikasi setelah fix: 50 tests pass (138 assertions), pint pass.

Putusan: OK merge.

## PR #5 — feat(trigger): add scheduled and webhook triggers

- Branch: `feature/workflow-triggers-scheduled-webhook`
- PR: https://github.com/Widiskel/flowforge/pull/5
- Status: merged

Catatan review:
- Scheduled trigger pakai Laravel scheduler + queue worker. Pastikan queue worker jalan di production (`php artisan queue:work`).
- Webhook signature verification pakai `hash_equals` — good, constant-time comparison buat ngelawan timing attack.
- `WorkflowTriggerController` sekarang bisa handle manual/scheduled/webhook via `TriggerWorkflowAction`. Intent-nya jelas, single responsibility.
- Cron validation di `ValidateCronRequest` pakai `CronExpression`. Solid choice, nggak perlu reinvent the wheel.

Tindak lanjut:
- Dokumentasi API endpoint `/api/workflows/{workflow}/trigger` + `/webhook/{workflow_id}/{signature}`.
- Add health check endpoint buat scheduler status (pending jobs, last run, etc).

Verifikasi setelah fix: 60 tests pass (165+ assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.

## PR #4 — feat(trigger): manual workflow run execution

- Branch: `feature/workflow-triggers`
- PR: https://github.com/Widiskel/flowforge/pull/4
- Status: merged

Catatan review:
- `WorkflowRunController::trigger` sebelumnya pakai ability `rollback`. Intent-nya salah, sebaiknya punya ability sendiri.
- `WorkflowRunPersister` selalu persist `attempt_count = 1`. Padahal executor support retry, jadi attempt count harus di-carry dari executor ke persister.

Tindak lanjut:
- Trigger sekarang pakai ability `trigger` di `WorkflowPolicy`.
- `WorkflowExecutor` return `StepResult` dengan `attemptCount` yang benar.
- `WorkflowRunPersister` persist `attempt_count` dari `StepResult`.

Verifikasi setelah fix: 54 tests pass (149 assertions), pint pass.

Putusan: OK merge.
