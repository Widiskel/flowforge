<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_failure_analyses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_run_id');
            $table->uuid('workflow_step_run_id')->nullable();
            $table->unsignedInteger('attempt_count')->default(1);
            $table->string('category')->default('unknown');
            $table->string('confidence')->default('low');
            $table->text('root_cause');
            $table->text('suggested_fix');
            $table->json('evidence')->nullable();
            $table->json('redacted_prompt_context')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'workflow_run_id']);
            $table->index(['workflow_step_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_failure_analyses');
    }
};
