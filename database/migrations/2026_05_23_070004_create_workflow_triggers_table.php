<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workflow_triggers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('workflow_id');
            $table->string('type');
            $table->string('cron_expression')->nullable();
            $table->string('timezone')->nullable();
            $table->string('webhook_secret')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamp('next_run_at')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'workflow_id']);
            $table->index(['type', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_triggers');
    }
};
