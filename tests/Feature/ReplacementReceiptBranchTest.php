<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Inventory;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReplacementReceiptBranchTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receipt_sold_at_this_branch_loads_when_the_cashier_is_assigned_elsewhere(): void
    {
        $sellingBranch = $this->branch('Main Branch');
        $assignedBranch = $this->branch('Other Branch');
        $cashier = $this->cashier($assignedBranch, 'cashier-other@naac.test');
        $product = $this->product('Zambo');
        $this->stock($product, $sellingBranch, 13);

        Sale::query()->create([
            'sale_number' => 'SAL-20261021-000004',
            'product_id' => $product->id,
            'processed_by_user_id' => $cashier->id,
            'unit_type' => '500 ml',
            'quantity' => 3,
            'total_price' => 750,
        ]);

        $response = $this->getJson('/api/flutter/replacements/receipt?'.http_build_query([
            'sale_number' => 'SAL-20261021-000004',
            'branch_id' => $sellingBranch->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.items.0.product.name', 'Zambo');
    }

    public function test_the_same_receipt_number_from_another_branch_does_not_hide_this_branch_sale(): void
    {
        $thisBranch = $this->branch('Main Branch');
        $otherBranch = $this->branch('Other Branch');
        $thisCashier = $this->cashier($thisBranch, 'cashier-main@naac.test');
        $otherCashier = $this->cashier($otherBranch, 'cashier-away@naac.test');
        $thisProduct = $this->product('Zambo');
        $otherProduct = $this->product('Advice');

        Sale::query()->create([
            'sale_number' => 'SAL-20261021-000004',
            'product_id' => $otherProduct->id,
            'processed_by_user_id' => $otherCashier->id,
            'branch_id' => $otherBranch->id,
            'quantity' => 1,
            'total_price' => 100,
        ]);
        Sale::query()->create([
            'sale_number' => 'SAL-20261021-000004',
            'product_id' => $thisProduct->id,
            'processed_by_user_id' => $thisCashier->id,
            'branch_id' => $thisBranch->id,
            'quantity' => 3,
            'total_price' => 750,
        ]);

        $response = $this->getJson('/api/flutter/replacements/receipt?'.http_build_query([
            'sale_number' => 'SAL-20261021-000004',
            'branch_id' => $thisBranch->id,
        ]));

        $response->assertOk();
        $response->assertJsonPath('data.items.0.product.name', 'Zambo');
        $response->assertJsonCount(1, 'data.items');
    }

    public function test_a_receipt_stored_for_another_branch_is_still_rejected(): void
    {
        $sellingBranch = $this->branch('Main Branch');
        $otherBranch = $this->branch('Other Branch');
        $cashier = $this->cashier($otherBranch, 'cashier-reject@naac.test');
        $product = $this->product('Zambo');
        $this->stock($product, $sellingBranch, 13);
        $this->stock($product, $otherBranch, 10);

        Sale::query()->create([
            'sale_number' => 'SAL-20261021-000004',
            'product_id' => $product->id,
            'processed_by_user_id' => $cashier->id,
            'branch_id' => $otherBranch->id,
            'quantity' => 1,
            'total_price' => 250,
        ]);

        $response = $this->getJson('/api/flutter/replacements/receipt?'.http_build_query([
            'sale_number' => 'SAL-20261021-000004',
            'branch_id' => $sellingBranch->id,
        ]));

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'This receipt was not sold at this branch.');
    }

    private function branch(string $name): Branch
    {
        return Branch::query()->create([
            'name' => $name,
            'location' => 'Naga',
        ]);
    }

    private function cashier(Branch $branch, string $email): User
    {
        $employee = Employee::query()->create([
            'branch_id' => $branch->id,
            'first_name' => 'Cashier',
            'last_name' => 'User',
            'contact_number' => (string) random_int(1000000000, 1999999999),
            'contact_email' => $email,
        ]);

        return User::query()->create([
            'employee_id' => $employee->id,
            'user_name' => strstr($email, '@', true),
            'name' => 'Cashier User',
            'email' => $email,
            'password' => 'password',
            'role' => 'staff',
        ]);
    }

    private function product(string $name): Product
    {
        return Product::query()->create([
            'name' => $name,
            'unit' => '500 ml',
            'price' => 250,
        ]);
    }

    private function stock(Product $product, Branch $branch, int $quantity): void
    {
        Inventory::query()->create([
            'product_id' => $product->id,
            'branch_id' => $branch->id,
            'quantity' => $quantity,
            'status' => 'in_stock',
        ]);
    }
}
