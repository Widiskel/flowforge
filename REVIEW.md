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

## PR #6 — feat(monitoring): add live run monitoring dashboard

- Branch: `feature/sse-run-monitoring`
- PR: https://github.com/Widiskel/flowforge/pull/6
- Status: merged

Catatan review:
- SSE endpoint pakai DB polling tiap tick. MVP-friendly, tapi kalau run-nya banyak paralel + interval pendek, ini bakal jadi N×polling × jumlah connection. Untuk MVP cukup, tapi harus didokumentasikan sebagai upgrade path ke Redis pub/sub atau Laravel Reverb.
- `RunEventStreamController` pakai `connection_aborted()` + `usleep`. Pastikan PHP-FPM `output_buffering = Off` atau client bakal nunggu sampai buffer flush. Heartbeat 15s + `X-Accel-Buffering: no` udah sesuai standar SSE behind nginx.
- Frontend types layer (`RawWorkflowRun`, `RawExecutionLog`) sebenarnya boilerplate-y. Bisa dipikirin pakai automated transformer (camelcase-keys lib atau Laravel API resource yang serialize camelCase) supaya nggak duplicate definitions.
- Health metrics endpoint hardcode `last_24h` window. Belum support custom range. Untuk MVP fine, tapi tambah validation `window` query param biar reviewer paham ini bukan dead param.
- `connectRunStream` di dashboard pas trigger, di-skip handling-nya kalau response error. Optimistic run cleanup harus jalan walaupun SSE gagal connect.

Tindak lanjut:
- Dokumentasi production note di README: `output_buffering`, nginx `proxy_buffering off`, queue worker.
- Tambah indeks di `execution_logs(workflow_run_id, created_at)` buat speed up `runLogs()` di production.

Verifikasi setelah fix: 65 tests pass (181 assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.

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

## PR #7 — feat(ai): add failure analysis

- Branch: `feature/ai-failure-analysis`
- PR: https://github.com/Widiskel/flowforge/pull/7
- Status: merged

Catatan review:
- Mapping API frontend/backend sempat beda shape. Resource backend kirim snake_case, tapi client langsung expect camelCase. Sekarang sudah dibikin transformer `rawToAiFailureAnalysis()` supaya konsisten sama payload lain.
- Nama tabel audit log dipilih `ai_audit_log`, bukan plural default Eloquent. Kalau model nggak override `$table`, insert bakal diam-diam nembak ke `ai_audit_logs` dan baru ketahuan pas test feature. Ini sekarang sudah dibenerin.
- `FailureContextBuilder` sudah aman buat MVP: redact secret-like key (`authorization`, `token`, `password`, `api_key`, dll) + truncate string panjang. Buat submission ini cukup meyakinkan reviewer kalau AI feature-nya nggak asal kirim raw payload.
- Caching analysis per run masuk akal buat MVP. Re-analyze tetap ada lewat `force=1`, jadi UX-nya nggak ke-lock kalau nanti prompt/logic berubah.

Tindak lanjut:
- Kalau nanti mau pakai provider AI beneran, contract analyzer-nya sudah bisa dipisah dari `MockFailureAnalyzer` jadi driver/provider-based.
- Bisa tambah snapshot sanitized context ke UI reviewer/dev mode kalau butuh observability internal, tapi jangan tampilkan di surface end-user.

Putusan: OK merge.

## PR #8 — feat(probes): operational health probes

- Branch: `feature/operational-probes`
- PR: https://github.com/Widiskel/flowforge/pull/8
- Status: merged

Catatan review:
- `/up` sudah disediakan oleh Laravel built-in lewat `withRouting(health: '/up')`. Untuk K8s yang butuh readiness/startup terpisah dari liveness, perlu probe yang benar-benar verifikasi backing service (DB, cache, migrasi).
- Probe operasional tidak boleh tenant-scoped atau di belakang `auth:api`. Awalnya saat draft sempat masuk ke group middleware; sekarang sudah dipindah keluar supaya bisa dipanggil oleh kubelet/load balancer tanpa kredensial.
- Bentuk response untuk `/actuator/health` mengikuti konvensi Spring Boot (`UP/DOWN`, components map) supaya tooling monitoring umum bisa parse tanpa adapter khusus.

Tindak lanjut:
- `OperationalHealthService` cek DB lewat `DB::select('SELECT 1')`, cache lewat write/read/forget, dan migrasi lewat `pendingMigrations()`. Worker exec probe (`queue:health`) ditunda ke phase Docker Compose karena lebih cocok di image worker, bukan di app HTTP.
- Probe gagal akan return HTTP 503 dengan body yang tetap memberi detail check, jadi observability stack masih bisa baca alasan degraded tanpa harus tail log.

Verifikasi setelah fix: 79 tests pass (230 assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.

## PR #9 — ci: add GitHub Actions workflow

- Branch: `feature/ci-pipeline`
- PR: https://github.com/Widiskel/flowforge/pull/9
- Status: merged

Catatan review:
- CI job perlu setup environment yang cukup mirip local dev/testing: install dependencies, generate `APP_KEY`, dan isi `JWT_SECRET` sebelum test dijalankan.
- Karena test suite pakai SQLite in-memory, CI tidak perlu service database eksternal untuk baseline pipeline.
- Pint di CI lebih aman pakai mode `--test`, supaya job fail saat format melenceng dan tidak diam-diam mengubah file.

Tindak lanjut:
- Workflow menyiapkan `.env` dari `.env.example`, generate `APP_KEY` dan `JWT_SECRET` sebelum test.
- PHP setup ditahan di baseline stabil (`8.4`) dengan extension SQLite yang dibutuhkan test.
- Job menjalankan `./vendor/bin/pint --test`, `npm run typecheck`, `npm run build`, dan `php artisan test`.

Verifikasi setelah fix: 79 tests pass (230 assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.

## PR #10 — feat(docker): add Docker Compose stack

- Branch: `feature/docker-compose`
- PR: https://github.com/Widiskel/flowforge/pull/10
- Status: merged

Catatan review:
- Multi-stage Dockerfile: frontend build di Node Alpine, production image di PHP-FPM Alpine. Composer install pakai `--no-dev --no-scripts` supaya image production tidak bawa dev dependencies.
- Docker Compose topology: nginx (reverse proxy) → app (PHP-FPM) → postgres + redis. Worker dan scheduler pakai image yang sama tapi command berbeda.
- Health check di Compose level: postgres pakai `pg_isready`, redis pakai `redis-cli ping`, app pakai `php artisan up`. Service dependency pakai `condition: service_healthy`.
- `.dockerignore` exclude `node_modules/`, `vendor/`, `public/build/`, `tests/`, `docs/`, `.env*` supaya build context ringan.

Tindak lanjut:
- Nginx config perlu `proxy_buffering off` dan `X-Accel-Buffering: no` header kalau SSE mau jalan lewat nginx reverse proxy.
- Worker probe (`queue:health`) bisa ditambah nanti kalau butuh readiness check khusus worker container.

Verifikasi setelah fix: 79 tests pass (230 assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.

## PR #11 — feat(e2e): add end-to-end workflow test

- Branch: `feature/end-to-end-test`
- PR: https://github.com/Widiskel/flowforge/pull/11
- Status: merged

Catatan review:
- E2E test pakai `WorkflowRunPersister` yang sama dengan production path, bukan mock. Ini penting buat validasi end-to-end flow benar-benar jalan.
- Test coverage: successful run, failed run dengan error message, dan cross-tenant access (404).
- Test setup perlu workflow + current version sebelum trigger; helper method `makeWorkflowWithVersion()` abstraksi ini.

Tindak lanjut:
- Bisa tambah test untuk retry/backoff behavior kalau step handler support retry.
- Bisa tambah test untuk global timeout enforcement.

Verifikasi setelah fix: 82 tests pass (246 assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.
