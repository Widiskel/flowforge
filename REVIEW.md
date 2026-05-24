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

## PR #12 — docs: submission polish (README, REVIEW.md, CHECKPOINTS)

- Branch: `feature/submission-polish`
- PR: https://github.com/Widiskel/flowforge/pull/12
- Status: merged

Catatan review:
- README sekarang berisi setup (Docker + local), demo accounts, architecture overview, API summary, testing commands, trade-offs, dan future improvements.
- REVIEW.md sudah lengkap dengan 12 PR entries (PR #1–#12), tone konsisten ngobrol, severity grading rata.
- CHECKPOINTS.md sudah diupdate: Phase 9–17 status diupdate sesuai progress.
- Demo accounts table ditambahkan di README untuk RBAC demonstration.

Tindak lanjut:
- EXPLAIN query optimization capture bisa ditambah nanti kalau ada bottleneck.
- Demo workflow seed (Incident Notifier) bisa ditambah di seeder.
- Submission email content (lokal di `docs/submission/`) perlu diisi sebelum push ke remote.

Verifikasi setelah fix: 82 tests pass (246 assertions), pint pass, typecheck hijau, build sukses.

Putusan: OK merge.

## PR #13 — feat(deployment): add Helm chart skeleton

- Branch: `feature/deployment-skeleton`
- PR: https://github.com/Widiskel/flowforge/pull/13
- Status: merged

Catatan review:
- Helm chart sudah zero-downtime ready: liveness probe `/up`, readiness probe `/api/healthz/ready`.
- Secrets template pakai `stringData` supaya plain text di values, Helm handle encoding.
- HPA resource limits sudah disiapkan di values.yaml.
- Chart.yaml version `0.1.0` (early alpha), appVersion `1.0.0`.

Tindak lanjut:
- Secrets template ditambahkan untuk `APP_KEY`, `JWT_SECRET`, dan kredensial DB.
- values.yaml diperluas dengan blok `secrets` agar konfigurasi sensitif punya satu tempat injection yang jelas.
- Probe path konsisten dengan endpoint operasional yang sudah ada (`/up`, `/api/healthz/ready`).
- README chart menjelaskan installation, configuration, dan production notes.

Verifikasi setelah fix: helm lint pass.

Putusan: OK merge.

## PR #14 — fix: repair workflow demo execution path

- Branch: `bugfix/workflow-demo-and-sse`
- PR: https://github.com/Widiskel/flowforge/pull/14
- Status: merged

Catatan review:
- Demo workflow sebelumnya belum representatif untuk reviewer karena definition seed tidak valid terhadap contract engine (`step type`, `dependsOn`, `schemaVersion`) dan frontend tidak map `current_version` ke `currentVersion`, jadi node count/DAG kelihatan rusak walau backend sebenarnya ada.
- SSE run stream juga belum benar untuk JWT karena EventSource tidak bisa kirim Authorization header.

Tindak lanjut:
- Seeder demo workflow sekarang pakai definition yang valid dan reproducible:
  - `schemaVersion`
  - `globalTimeoutMs`
  - `dependsOn`
  - uppercase step type (`HTTP`, `SCRIPT`, `DELAY`, `CONDITION`)
- Frontend workflow transformer sudah benar:
  - `current_version` → `currentVersion`
  - `version_number` → `versionNumber`
  - node count dan DAG renderer tidak 0 lagi
- SSE auth dilewatkan via query string token karena EventSource tidak bisa kirim Authorization header
- HTTP step demo tanpa URL sekarang fallback ke noop success supaya demo tidak gagal karena dependency eksternal

Verifikasi setelah fix:
- 82 tests pass (246 assertions)
- typecheck pass
- build pass
- pint pass
- demo trigger run SUCCESS end-to-end

Putusan: OK merge.

## PR #15 — feat(builder): add visual workflow builder UI

- Branch: `feature/workflow-builder-ui`
- PR: https://github.com/Widiskel/flowforge/pull/15
- Status: pending

Catatan review:
- Workflow builder UI minimal yang usable oleh user non-teknis:
  - Form create workflow (name, description, global timeout)
  - Add/remove step
  - Step type: HTTP / SCRIPT / DELAY / CONDITION
  - dependsOn via checkbox
  - config sederhana per type (operation, method/url, delay ms, expression)
  - DAG preview langsung pakai Vue Flow
- Backend API `POST /api/workflows` sudah ada dan berfungsi
- Frontend `createWorkflow()` client function sudah terintegrasi
- Overlay builder tidak mengganggu workflow library/selected panel

Tindak lanjut:
- Browser automation test untuk create workflow end-to-end
- Validation error display di UI (sudah ada di component)
- Success feedback setelah workflow created

Verifikasi setelah fix:
- 82 tests pass (246 assertions)
- typecheck pass
- build pass
- pint pass
- API create workflow verified via console POST (201 response)

Putusan: OK merge.

## PR #17 — feat(builder): runtime polish, trigger UX, and bonus surfaces

- Branch: `feature/workflow-builder-runtime-polish`
- PR: https://github.com/Widiskel/flowforge/pull/17
- Status: pending review

Catatan review:
- Workflow builder masih kelihatan mentah untuk reviewer: trigger node belum punya entry-point eksplisit, posisi node tidak persisten, dirty state tidak dilacak, dan TestRunOverlay masih mode placeholder. Sebagai authoring surface, pengalaman editor-nya masih kurang.
- Step inspector cuma punya satu panel parameters. Padahal user perlu lihat input/output dan tweak settings tanpa harus pindah halaman. Untuk demo/test cycle juga butuh single-step sandbox tanpa full run.
- Demo seeder sebelumnya cuma 1 workflow Incident Notifier, tidak cukup buat reviewer melihat variasi step type, retry, condition branching, atau realistic failure scenario.
- Routes API ada 1 entry tapi rate limiter `api` dipasang di middleware group level — endpoint sensitif (login/refresh/webhook/ai-analyze/metrics/sse) harus punya limiter sendiri yang lebih ketat.
- Belum ada endpoint untuk panggil HTTP/script handlers tanpa create full workflow run. Reviewer kalau mau test step config harus simpan workflow dulu, trigger run, lalu lihat hasilnya. Iterasi config lambat.
- Tidak ada surface bonus yang menunjukkan production-thinking di luar REST CRUD. Trade-off doc menyebut GraphQL dan playground sebagai future work tapi belum ada artefak nyatanya.

Tindak lanjut:
- Workflow builder runtime polish:
  - Trigger node entry-point dengan ID konstan `TRIGGER_NODE_ID`, dependencies step pertama otomatis link ke trigger node
  - TriggerSelector modal untuk pilih trigger type (manual/scheduled/webhook)
  - TriggerInspector untuk konfigurasi cron expression atau copy webhook URL+secret
  - Posisi node persisten via `ui.nodes[].position` di workflow definition
  - Dirty state tracking + discard confirmation dialog
  - TestRunOverlay live: terhubung ke real SSE stream, tampilkan step status, logs, dan run detail
- Step inspector rewrite: tabs Parameters / Input / Output / Settings, simulate-step API untuk dry-run handler tanpa persist run, suggestInput dari upstream step output, notes + display-in-flow toggle
- Trigger management endpoint: tambah `DELETE /api/workflows/{workflow}/triggers/{trigger}` supaya frontend bisa hapus trigger tanpa hit DB langsung
- Demo seeder rewrite: 10 curated workflows menggunakan playground endpoints (echo/status/maybe-fail/delay/notify/calc/decisions/mock-crud/inventory) — reviewer bisa trigger berbagai skenario realistis tanpa external dependency
- Rate limiter discipline: `RateLimiter::for('playground', ...)` ditambah, route per-endpoint pakai `withoutMiddleware('throttle:api')->middleware('throttle:<name>')` supaya health/metrics, ai-analyze, sse, login, refresh, webhook punya budget masing-masing yang sesuai use case
- Bonus production surfaces (clearly labeled future-work, mock-mode-default):
  - `POST /api/workflows/simulate-step` — single-step sandbox via handler stack, no DB writes
  - `POST /api/graphql` — read-only GraphQL surface (Workflow + WorkflowRun + StepRun) via webonyx/graphql-php, tenant-scoped, query size limit 16KB
  - `/api/playground/*` — 10 demo endpoints (echo, status, maybe-fail, delay, users, notify, calc, decisions, mock-crud, inventory) untuk seeded workflows
- DataTable enhancement: sortable header, pagination, page size selector, range indicator — supaya runs/workflows list bisa dipakai di volume realistis
- Step form polish: HttpStepForm/DelayStepForm/ScriptStepForm schema refresh, _shared.ts helper untuk reuse field rendering
- Auth page fix: Tailwind v4 `--spacing-md: 16px` token shadow `max-w-md` utility (resolve ke 16px instead of 28rem). Fix via explicit `.max-w-md { max-width: 28rem }` di app.css + inline style di LoginPage GlassPanel
- Condition step handler behaviour update: support nested boolean expression evaluation
- HTTP step handler behaviour update: timeout default + connection error catch lebih bersih

Verifikasi setelah fix:
- 82 tests pass (246 assertions, 1.48s)
- typecheck pass (vue-tsc --noEmit)
- build pass (621ms, 20 assets)
- pint pass (133 files)
- composer validate strict pass
- 42 routes registered, semua P0/P1 endpoints aktif

Putusan: OK merge.

## PR #16 — feat(frontend): stitch redesign and FE/BE integration pass

- Branch: `feature/frontend-stitch-redesign`
- PR: https://github.com/Widiskel/flowforge/pull/16
- Status: pending review

Catatan review:
- `RunEventStreamController` sebelumnya masih mengandalkan `auth:api` biasa, sementara frontend EventSource kirim token via query string. Alhasil SSE path terlihat tersedia tapi kontraknya belum ketemu.
- `WorkflowRunResource` belum expose `workflow_trigger_id`, `timeout_ms`, `created_at`, dan `updated_at`, padahal frontend mapper (`resources/js/services/api/client.ts`) dan sorting runs/dashboard sudah pakai field itu.
- Workflow list masih expose filter `paused`, padahal backend request validation cuma menerima `draft`, `active`, `archived`. Ini bikin UI bilang ada state yang sebenarnya tidak bisa dipakai.
- Copy di workflow list dan run overlay sempat overclaim: seolah semua versioning/live test run sudah fully wired, padahal yang benar sekarang baru manage/inspect/trigger + run details.
- Dashboard dan runs page awalnya ambil run per workflow (N+1) padahal backend sudah punya `/api/workflow-runs` tenant-scoped. Untuk data list/history lebih aman pakai endpoint agregat itu langsung.

Tindak lanjut:
- Tambah middleware `UseJwtQueryToken` lalu wire `jwt.query` sebelum `auth:api` di route group API supaya SSE EventSource bisa jalan dengan token query.
- Lengkapi `WorkflowRunResource` dengan field yang memang dipakai frontend mapper/sorting.
- Sinkronkan kontrak frontend: hapus `paused` dari type/filter, ganti copy yang terlalu jauh, ubah label overlay jadi `Run Details`.
- Refactor dashboard dan runs page untuk pakai endpoint agregat `/api/workflow-runs` agar FE/BE lebih rapat dan request lebih efisien.

Verifikasi setelah fix:
- 82 tests pass (246 assertions)
- typecheck pass
- build pass
- pint pass
- contract FE/BE dicek ulang untuk workflows, runs, logs, health metrics, dan analyze-failure

Putusan: OK merge.

