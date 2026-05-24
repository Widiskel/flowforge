<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Backing store for the public playground CRUD endpoints. The playground
     * is shared (anyone can read/write) and intentionally has no tenant_id —
     * it exists so demo workflows can exercise real database mutation through
     * a public, throttled, self-cleaning sandbox.
     *
     * Rows are pruned by `PlaygroundController::pruneStaleRows()` on every
     * request so the table stays small without a scheduled job.
     */
    public function up(): void
    {
        Schema::create('playground_items', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('quantity')->default(0);
            $table->unsignedInteger('price_cents')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('created_at', 'playground_items_created_at_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playground_items');
    }
};
