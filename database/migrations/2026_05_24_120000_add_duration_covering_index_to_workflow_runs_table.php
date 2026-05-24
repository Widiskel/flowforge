<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add a covering index that backs the dashboard / health-metrics query
     * for the last 24 hours of terminal runs. The query reads:
     *
     *     select status, started_at, finished_at, duration_ms
     *     from workflow_runs
     *     where tenant_id = ?
     *       and status in ('SUCCESS', 'FAILED', 'TIMEOUT', 'CANCELLED')
     *       and finished_at is not null
     *       and finished_at >= ?
     *     order by finished_at desc;
     *
     * On Postgres this lets the planner satisfy the read from the index alone
     * (Index Only Scan) for the duration aggregation. On SQLite the same
     * leading-column ordering keeps the scan tenant-bounded.
     *
     * Expand-only: we add a new index without dropping the existing
     * `workflow_runs_tenant_status_finished_idx` so older queries still
     * benefit from a tenant + status + window key. Safe to deploy without
     * coordination.
     */
    public function up(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->index(
                ['tenant_id', 'status', 'finished_at', 'duration_ms'],
                'workflow_runs_tenant_status_finished_duration_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::table('workflow_runs', function (Blueprint $table): void {
            $table->dropIndex('workflow_runs_tenant_status_finished_duration_idx');
        });
    }
};
