<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SaleInventoryDeductionTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_flutter_sale_deducts_branch_inventory_even_without_a_cashier_user(): void
    {
        $product = $this->saleProduct();
        $branch = $this->saleBranch();
        $older = $this->saleBatch($product, $branch, 13);
        $newer = $this->saleBatch($product, $branch, 16);

        $response = $this->postJson('/api/flutter/sales', [
            'product_id' => $product->id,
            'quantity' => 3,
            'unit_type' => '500 ml',
            'idempotency_key' => 'zambo-sale-1',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Sale::query()->count());
        $this->assertSame(10, $older->fresh()->quantity);
        $this->assertSame(16, $newer->fresh()->quantity);
    }

    public function test_a_flutter_sale_with_branch_id_deducts_across_older_batches_first(): void
    {
        $product = $this->saleProduct();
        $branch = $this->saleBranch();
        $older = $this->saleBatch($product, $branch, 13);
        $newer = $this->saleBatch($product, $branch, 16);

        $response = $this->postJson('/api/flutter/sales', [
            'product_id' => $product->id,
            'quantity' => 29,
            'unit_type' => '500 ml',
            'branch_id' => $branch->id,
            'idempotency_key' => 'zambo-sale-29',
        ]);

        $response->assertCreated();
        $this->assertSame(0, $older->fresh()->quantity);
        $this->assertSame(0, $newer->fresh()->quantity);
        $this->assertSame('out_of_stock', $older->fresh()->status);
        $this->assertSame('out_of_stock', $newer->fresh()->status);
    }

    public function test_a_flutter_sale_still_succeeds_when_the_product_has_no_branch_inventory(): void
    {
        $product = $this->saleProduct();

        $response = $this->postJson('/api/flutter/sales', [
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_type' => '500 ml',
            'idempotency_key' => 'no-stock-sale',
        ]);

        $response->assertCreated();
        $this->assertSame(1, Sale::query()->count());
    }

    private function saleProduct(): Product
    {
        return Product::query()->create([
            'name' => 'Zambo',
            'unit' => '500 ml',
            'price' => 250,
        ]);
    }

    private function saleBranch(): Branch
    {
        return Branch::query()->create([
            'name' => 'Main Branch',
            'location' => 'Naga',
        ]);
    }

    private function saleBatch(Product $product, Branch $branch, int $quantity): Inventory
    {
        return Inventory::query()->create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $quantity,
            'status' => 'in_stock',
        ]);
    }
}
