<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_run_id');
            $table->uuid('workflow_step_run_id')->nullable();
            $table->string('level')->default('info');
            $table->string('event');
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['tenant_id', 'workflow_run_id', 'created_at'], 'execution_logs_tenant_run_created_idx');
            $table->index(['workflow_step_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_logs');
    }
};
