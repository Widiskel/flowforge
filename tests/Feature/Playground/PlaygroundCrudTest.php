<?php

declare(strict_types=1);

namespace Tests\Feature\Playground;

use App\Models\PlaygroundItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The playground CRUD endpoints back demo workflows that mutate real DB rows.
 * Pin the contract so the demo path can't silently regress.
 */
class PlaygroundCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_returns_paginated_envelope(): void
    {
        PlaygroundItem::query()->create(['name' => 'Widget A', 'quantity' => 12, 'price_cents' => 599]);
        PlaygroundItem::query()->create(['name' => 'Widget B', 'quantity' => 3, 'price_cents' => 1599]);

        $response = $this->getJson('/api/playground/items?per_page=25');

        $response->assertOk();
        $response->assertJsonPath('service', 'flowforge-playground');
        $response->assertJsonStructure([
            'data' => [['id', 'name', 'quantity', 'price_cents']],
            'meta' => ['total', 'per_page', 'current_page', 'last_page'],
        ]);
        $this->assertSame(2, $response->json('meta.total'));
    }

    public function test_create_persists_row(): void
    {
        $response = $this->postJson('/api/playground/items', [
            'name' => 'Demo widget',
            'description' => 'Created by feature test',
            'quantity' => 7,
            'price_cents' => 1299,
            'metadata' => ['tag' => 'demo'],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.name', 'Demo widget');
        $response->assertJsonPath('data.metadata.tag', 'demo');
        $this->assertDatabaseCount('playground_items', 1);
    }

    public function test_create_validates_payload(): void
    {
        $response = $this->postJson('/api/playground/items', [
            'name' => '',
            'quantity' => -5,
        ]);

        $response->assertStatus(422);
    }

    public function test_show_returns_404_for_missing_row(): void
    {
        $response = $this->getJson('/api/playground/items/00000000-0000-0000-0000-000000000000');

        $response->assertStatus(404);
        $response->assertJsonPath('error', 'not_found');
    }

    public function test_update_persists_partial_changes(): void
    {
        $item = PlaygroundItem::query()->create(['name' => 'Original', 'quantity' => 1, 'price_cents' => 100]);

        $response = $this->patchJson("/api/playground/items/{$item->id}", [
            'quantity' => 50,
            'description' => 'Updated description',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.quantity', 50);
        $response->assertJsonPath('data.description', 'Updated description');
        $response->assertJsonPath('data.name', 'Original');
    }

    public function test_delete_removes_row(): void
    {
        $item = PlaygroundItem::query()->create(['name' => 'Doomed', 'quantity' => 0, 'price_cents' => 0]);

        $response = $this->deleteJson("/api/playground/items/{$item->id}");

        $response->assertOk();
        $response->assertJsonPath('deleted', true);
        $this->assertDatabaseMissing('playground_items', ['id' => $item->id]);
    }

    public function test_inventory_report_summarizes_rows(): void
    {
        PlaygroundItem::query()->create(['name' => 'A', 'quantity' => 10, 'price_cents' => 500]);
        PlaygroundItem::query()->create(['name' => 'B', 'quantity' => 2, 'price_cents' => 200]);
        PlaygroundItem::query()->create(['name' => 'C', 'quantity' => 1, 'price_cents' => 100]);

        $response = $this->getJson('/api/playground/inventory');

        $response->assertOk();
        $response->assertJsonPath('inventory.total_items', 3);
        $response->assertJsonPath('inventory.total_quantity', 13);
        $response->assertJsonPath('inventory.total_value_cents', 10 * 500 + 2 * 200 + 1 * 100);
        // Two rows have quantity < 5, so reorder is required.
        $response->assertJsonPath('inventory.low_stock_items', 2);
        $response->assertJsonPath('inventory.reorder_required', false);
    }

    public function test_auto_prune_keeps_table_under_cap(): void
    {
        // Seed 102 rows with deterministic created_at order so pruning has a
        // clear oldest cohort to remove.
        for ($i = 0; $i < 102; $i++) {
            PlaygroundItem::query()->create([
                'name' => "row-$i",
                'quantity' => $i,
                'price_cents' => 0,
                'created_at' => now()->subSeconds(102 - $i),
                'updated_at' => now()->subSeconds(102 - $i),
            ]);
        }

        // List endpoint triggers pruning.
        $this->getJson('/api/playground/items')->assertOk();

        $this->assertSame(100, PlaygroundItem::query()->count());
    }
}
