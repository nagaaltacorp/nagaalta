<?php

use App\Models\Branch;
use App\Models\Employee;
use App\Models\Product;
use App\Models\ProductCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function categoryAdmin(): User
{
    $branch = Branch::query()->create([
        'name' => 'Main',
        'location' => 'Naga',
    ]);
    $employee = Employee::query()->create([
        'branch_id' => $branch->id,
        'first_name' => 'Admin',
        'last_name' => 'User',
        'contact_number' => '09170000001',
        'contact_email' => 'categories-admin@example.com',
    ]);

    return User::query()->create([
        'employee_id' => $employee->id,
        'user_name' => 'categories-admin',
        'name' => 'Admin User',
        'email' => 'categories-admin@example.com',
        'password' => 'password',
        'role' => 'admin',
    ]);
}

test('an admin can add a product category and product setup must use it', function () {
    $admin = categoryAdmin();

    $created = $this->actingAs($admin)->postJson('/api/product-categories', [
        'name' => '  Seeds  ',
    ]);

    $created->assertCreated();
    $created->assertJsonPath('data.name', 'Seeds');

    $duplicate = $this->actingAs($admin)->postJson('/api/product-categories', [
        'name' => 'seeds',
    ]);
    $duplicate->assertStatus(422);
    expect(ProductCategory::query()->count())->toBe(1);

    $list = $this->getJson('/api/product-categories');
    $list->assertOk();
    expect($list->json('data.0.name'))->toBe('Seeds');

    $product = $this->postJson('/api/products', [
        'name' => 'Hybrid Rice',
        'category' => 'seeds',
        'unit' => 'sack',
        'price' => 100,
    ]);

    $product->assertCreated();
    $product->assertJsonPath('data.category', 'Seeds');

    $unknown = $this->postJson('/api/products', [
        'name' => 'Urea',
        'category' => 'Fertilizer',
        'unit' => 'bag',
        'price' => 50,
    ]);
    $unknown->assertStatus(422);
    expect(Product::query()->count())->toBe(1);
});

test('a category used by a product cannot be removed', function () {
    $admin = categoryAdmin();
    $category = ProductCategory::query()->create(['name' => 'Fertilizer']);
    Product::query()->create([
        'name' => 'Urea',
        'category' => 'Fertilizer',
        'unit' => 'bag',
        'price' => 50,
    ]);

    $blocked = $this->actingAs($admin)->deleteJson('/api/product-categories/'.$category->id);
    $blocked->assertStatus(422);
    expect(ProductCategory::query()->count())->toBe(1);

    Product::query()->delete();

    $removed = $this->actingAs($admin)->deleteJson('/api/product-categories/'.$category->id);
    $removed->assertOk();
    expect(ProductCategory::query()->count())->toBe(0);
});

test('a staff user cannot add product categories', function () {
    $admin = categoryAdmin();
    $admin->update(['role' => 'staff']);

    $response = $this->actingAs($admin)->postJson('/api/product-categories', [
        'name' => 'Seeds',
    ]);

    $response->assertForbidden();
    expect(ProductCategory::query()->count())->toBe(0);
});
