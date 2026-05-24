# FlowForge

FlowForge adalah Real-Time Multi-Tenant Workflow Orchestration Engine — versi sederhana gabungan Zapier workflow engine dan GitHub Actions execution model. Project ini dibangun sebagai submission technical test SEVIMA untuk posisi Software Engineer.

## Stack

- **Backend**: Laravel 13, PHP 8.4+, PostgreSQL, Redis
- **Frontend**: Vue 3, TypeScript, Vite, Vue Flow
- **Auth**: JWT bearer token + refresh token rotation
- **AI**: Deterministic mock failure analyzer (production-ready abstraction)
- **Infra**: Docker Compose (nginx + app + worker + scheduler + postgres + redis)

## Setup

### Quick start dengan Docker Compose

```bash
git clone https://github.com/Widiskel/flowforge.git
cd flowforge
cp .env.example .env

# Generate APP_KEY dan JWT_SECRET
docker compose run --rm app php artisan key:generate
docker compose run --rm app php artisan jwt:secret --force

# Run migrations
docker compose run --rm app php artisan migrate --seed

# Start stack
docker compose up -d
```

Akses aplikasi di `http://localhost`.

### Local development

Requirements: PHP 8.4+, Node 20+, PostgreSQL 14+, Redis 6+.

```bash
git clone https://github.com/Widiskel/flowforge.git
cd flowforge

# Install dependencies
composer install
npm install

# Setup environment
cp .env.example .env
php artisan key:generate
php artisan jwt:secret --force

# Configure database di .env, lalu:
php artisan migrate --seed

# Run dev servers (di 2 terminal)
php artisan serve              # Backend di :8000
npm run dev                    # Frontend dev server
```

## Demo accounts

Database seeder menyediakan 3 user untuk demo RBAC:

| Email | Password | Role |
|-------|----------|------|
| admin@flowforge.test | password | Admin |
| editor@flowforge.test | password | Editor |
| viewer@flowforge.test | password | Viewer |

## Architecture overview

FlowForge adalah modular monolith dengan domain-driven structure:

```
app/
├── Actions/           # Use case orchestrators (TriggerWorkflowAction, AnalyzeRunFailureAction)
├── Domain/
│   ├── Workflow/      # DAG validator, planner, executor, step handlers
│   └── Ai/            # Failure context builder + mock analyzer
├── Http/
│   ├── Controllers/   # Thin controllers (delegate ke actions)
│   ├── Middleware/    # ResolveTenant, auth:api
│   ├── Requests/      # FormRequest validation
│   └── Resources/     # API response shaping
├── Models/            # Eloquent models dengan tenant_id scoping
├── Policies/          # WorkflowPolicy (admin/editor/viewer matrix)
└── Services/          # OperationalHealthService, JwtRefreshTokenStore
```

Workflow execution model: DAG validator → topological planner → executor (dengan retry/backoff/timeout) → persister.

## API summary

Auth:
- `POST /api/auth/login` — JWT bearer + refresh token
- `POST /api/auth/refresh` — Rotate refresh token (single-use, reuse detection)
- `POST /api/auth/logout` — Revoke refresh token
- `GET /api/auth/me` — Current user context

Workflows (tenant-scoped):
- `GET /api/workflows` — List dengan pagination/filter
- `POST /api/workflows` — Create (admin/editor)
- `GET /api/workflows/{id}` — Detail dengan current version
- `PUT /api/workflows/{id}` — Update (admin/editor)
- `DELETE /api/workflows/{id}` — Soft delete (admin only)
- `GET /api/workflows/{id}/versions` — Version history
- `POST /api/workflows/{id}/rollback/{version}` — Rollback as new version

Triggers:
- `GET /api/workflows/{id}/triggers` — List triggers
- `POST /api/workflows/{id}/triggers` — Create scheduled/webhook trigger
- `POST /api/workflows/{id}/trigger` — Manual trigger
- `POST /api/webhooks/{workflow}` — Webhook endpoint (HMAC verified)

Runs & monitoring:
- `GET /api/workflow-runs` — List runs dengan filter
- `GET /api/workflow-runs/{id}` — Run detail dengan step runs
- `GET /api/workflow-runs/{id}/events` — SSE stream untuk live updates
- `GET /api/workflow-runs/{id}/logs` — Execution logs
- `POST /api/workflow-runs/{id}/analyze-failure` — AI failure analysis
- `GET /api/health/metrics?window=last_24h` — Business metrics aggregate

Operational probes (public, untuk K8s/load balancer):
- `GET /up` — Laravel built-in liveness
- `GET /api/healthz/ready` — Readiness (DB + cache + migrations)
- `GET /api/healthz/startup` — Startup probe
- `GET /api/actuator/health` — Spring Boot-style UP/DOWN

## Testing

```bash
./vendor/bin/pint --test     # Lint check
npm run typecheck            # TypeScript check
npm run build                # Frontend build
php artisan test             # Test suite (99 tests, 284 assertions)
```

## Query optimization

The dashboard / health-metrics aggregation reads terminal runs in the last 24 hours per tenant:

```sql
select status, started_at, finished_at, duration_ms
from workflow_runs
where tenant_id = ?
  and status in ('SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED')
  and finished_at is not null
  and finished_at >= ?
order by finished_at desc;
```

To keep this read tenant-bounded and avoid a heap touch on the duration column, the schema ships a composite covering index:

```text
workflow_runs_tenant_status_finished_duration_idx
(tenant_id, status, finished_at, duration_ms)
```

Migration: `database/migrations/2026_05_24_120000_add_duration_covering_index_to_workflow_runs_table.php` (expand-only — the older `(tenant_id, status, finished_at)` index stays in place so existing code paths keep their plan).

Expected Postgres plan shape:

```text
Index Only Scan using workflow_runs_tenant_status_finished_duration_idx
  Index Cond:
    tenant_id = $1
    AND status = ANY ('{SUCCESS,FAILED,TIMEOUT,CANCELLED}')
    AND finished_at >= $2
```

The plan is verified end-to-end through `tests/Feature/MonitoringMetricsTest.php`, which asserts both the tenant scoping and the `duration_ms`-vs-timestamp-diff fallback.

## AI failure analysis (Intelligent Enhancement)

Failed workflow runs surface a `POST /api/workflow-runs/{run}/analyze-failure` endpoint that returns structured diagnostic JSON:

```json
{
  "rootCause": "Step fetch_status failed: HTTP request timed out after 10000ms",
  "suggestedFix": "Increase the HTTP timeout or check upstream latency before retrying.",
  "confidence": "medium",
  "category": "http_timeout",
  "evidence": [
    { "observation": "HTTP request timed out after 10000ms", "source": "retry_history" }
  ]
}
```

Design choices:

- **Mock-first by default** (`AI_PROVIDER=mock`). Reviewers can run the feature without API keys; a real provider can be plugged in later via the same `FailureAnalyzer` driver contract.
- **Sanitization before prompt assembly**. `FailureContextBuilder` redacts keys matching `authorization|cookie|token|password|secret|api[_-]?key` and truncates oversized string values. No request payload, header, or log entry leaves the process raw.
- **Bounded inputs**. Only the failed step's metadata, retry history, and a capped log slice make it into the prompt.
- **Tenant-scoped + RBAC**. Admin/Editor only, same tenant; cross-tenant or non-failed runs return 4xx (verified in tests).
- **Persisted + idempotent**. Analyses cache to `ai_failure_analyses`. The UI's "Re-analyze" forces regeneration; everything else reads from cache.

## Production infrastructure (highlights)

Target deployment is a Rancher cluster reconciled by Fleet from this repo. The shape:

- **Single multi-stage Dockerfile** (`docker/Dockerfile`) — Node builder for the Vite assets, then a slim `php-fpm-alpine` runtime. Local stack mirrors the topology via `docker-compose.yml` (nginx + app + worker + scheduler + postgres + redis).
- **Three health endpoints, three concerns**: `/up` for liveness (cheap), `/api/healthz/ready` for readiness (DB + cache + migrations), `/api/healthz/startup` for slow boot. `/api/health/metrics?window=last_24h` is a separate **business** metric, never used for probing.
- **Migrations as a Helm pre-upgrade hook**, not an init container — pre-upgrade runs once per release, init runs once per pod and would race the rolling update.
- **Worker tier** is its own Deployment with a `terminationGracePeriodSeconds` long enough to drain in-flight jobs (`SIGTERM` → `php artisan queue:work --max-jobs=...` exits cleanly).
- **Scheduler as a CronJob**, not a long-running container, so we don't keep an idle pod alive between ticks.

Full design rationale (image build order, probe scripts, autoscaling targets, secrets path) lives behind the production checklist.

## Trade-offs

- **JWT library**: `php-open-source-saver/jwt-auth` is the actively maintained fork of `tymon/jwt-auth` and supports Laravel 11+. Sanctum was deliberately rejected because the API contract is multi-tenant SaaS-style (bearer + refresh rotation), not first-party SPA cookies.
- **AI mock-only by default**: `FailureAnalyzer` is wired through a driver abstraction so a real LLM provider can replace the mock without changing controllers, resources, or tests. The mock is deterministic, which keeps CI fast and lets reviewers run the feature without keys.
- **Modular monolith**: single Laravel repo with a domain folder structure (`Actions/`, `Domain/`, `Http/`, `Policies/`). A package monorepo or microservice split would buy weak isolation at the cost of much heavier ops, which is a poor trade for an MVP.
- **SSE polls the DB per tick**: each tick reads the run + step rows and emits a snapshot frame. Scale-out path is Redis pub/sub or Laravel Reverb; for a single-pod demo, polling avoids an extra dependency.
- **Execution logs in PostgreSQL**: append-heavy traffic, eventually a Loki / ClickHouse problem. Until volume warrants it, the `execution_logs` table with a `(workflow_run_id, created_at)` index is plenty fast and keeps the data layer simple.
- **SCRIPT step is sandboxed JavaScript**: the `SCRIPT` handler hands user source to a Node 18 child process with `$doc`-shaped predefined globals (input/config/output), `fetch`, `URL`, and `console`. Filesystem, child_process, vm, and low-level network modules are wiped from `require.cache` before user code runs; environment is scrubbed to `LANG`. Hard limits: 8s wall-clock, 16 KB script length. Inspired by Frappe's server/client script affordances — small documented helper surface, no arbitrary-shell escape hatch.
- **HTTP step SSRF guard**: URLs go through a scheme allowlist (`http`/`https`) and, when `HTTP_STEP_ALLOW_PRIVATE_NETWORK=false`, a private/loopback IP filter before the HTTP client touches the network. Default is permissive in dev so the playground works against `127.0.0.1`; production sets it strict so workflow authors can't pivot to internal services.
- **Single-worker dev server caveat**: `php artisan serve` runs one PHP worker by default, which deadlocks when an HTTP step calls back into the same server. `composer dev` exports `PHP_CLI_SERVER_WORKERS=4` so the dev experience matches production behavior.
- **`last_24h` metrics ≠ readiness probe**: `/api/health/metrics?window=last_24h` is a tenant-scoped business aggregate. Operational probes (`/up`, `/healthz/ready`, `/healthz/startup`, `/actuator/health`) are separate, public, and don't touch tenant data. Mixing the two — common in MVPs — makes load balancers misbehave when the business hits a slow window.
- **Workflow definition position metadata in JSON**: the builder persists per-step `position` and a top-level `ui.triggerPosition` inside the same `definition` JSON that the executor reads. The validator ignores unknown keys, so layout survives save/reload without a separate UI metadata table. If the schema ever becomes strict allowlist, this needs a dedicated column.
- **Trigger update via delete+create**: `WorkflowTrigger` has no PATCH endpoint. The frontend reconciles a draft trigger against the persisted row and replaces the whole record when type, cron, secret, or enabled flag changes. Trigger-history audit is intentionally out of scope for the MVP.

## Future improvements

- Real LLM provider integration via the existing `FailureAnalyzer` driver contract (current default: deterministic mock).
- Redis pub/sub or Laravel Reverb for SSE scale-out across multiple app pods.
- Worker readiness probe via `php artisan queue:health` exec script in K8s manifests.
- Helm chart hardening + Rancher Fleet GitOps wiring across staging and production clusters.
- Append-only log store (ClickHouse or Loki) for execution logs once volume justifies the operational cost.
- Prometheus `/metrics` endpoint for operational telemetry (currently surfaced only as business metrics in `/api/health/metrics`).
- Per-tenant rate limiting at the route group level — current limits are global-named.
- Trigger update endpoint + audit trail (current MVP replaces trigger rows wholesale).
- Workflow position metadata moved to a dedicated UI table once the definition schema needs a strict allowlist.

## License

MIT — see [LICENSE](LICENSE).
