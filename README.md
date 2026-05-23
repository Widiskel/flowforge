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
php artisan test             # Test suite (82 tests, 246 assertions)
```

## Trade-offs

- **JWT library**: `php-open-source-saver/jwt-auth` dipilih karena fork aktif dari `tymon/jwt-auth`, support Laravel 11+. Sanctum sengaja tidak dipakai untuk konsistensi dengan multi-tenant SaaS API contract.
- **AI mock-only**: Failure analysis pakai deterministic mock provider. Real LLM provider bisa dipasang lewat driver abstraction tanpa breaking API surface.
- **Modular monolith**: Single Laravel repo dengan domain folder structure. Microservice/package monorepo split dipertimbangkan tapi over-engineering untuk MVP scope.
- **SSE polling**: Run event stream pakai DB polling per tick. Scale-out path: Redis pub/sub atau Laravel Reverb. Untuk MVP demo cukup.
- **PostgreSQL logs**: Execution logs disimpan di PostgreSQL JSON column. Production scale path: append-only log store seperti ClickHouse atau Loki.
- **Restricted script step**: Script step pakai allowlist (`noop`, `set_output`, `transform`, `fail_demo`), bukan arbitrary shell. Trade-off: kurang fleksibel, tapi aman dari RCE risk.
- **`last_24h` business metric vs readiness probe**: Health metrics endpoint (`/api/health/metrics`) adalah business window aggregate, terpisah dari operational readiness probes. Reviewer-clarity > convenience.

## Future improvements

- Real LLM provider integration via driver abstraction (current: mock only)
- Redis pub/sub untuk SSE scale-out
- Worker readiness probe via `php artisan queue:health`
- Helm chart + Rancher Fleet untuk multi-cluster deployment
- ClickHouse/Loki integration untuk execution logs
- Prometheus metrics endpoint untuk operational telemetry
- Per-tenant rate limiting di route group level

## License

MIT — see [LICENSE](LICENSE).
