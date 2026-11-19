<?php

use App\Models\Creditor;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function creditProduct(): Product
{
    return Product::query()->create([
        'name' => 'Rice',
        'unit' => 'sack',
        'price' => 100,
    ]);
}

function creditSale(Product $product, array $overrides = []): array
{
    return array_merge([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_type' => 'sack',
        'payment_method' => 'utang',
        'borrower_name' => 'Maria Santos',
        'borrower_phone' => '09171234567',
        'due_date' => '2026-10-20',
        'sale_number' => 'SAL-CREDIT-1',
        'idempotency_key' => 'credit-customer-1',
    ], $overrides);
}

test('the first credit sale records the customer once', function () {
    $response = $this->postJson('/api/flutter/sales', creditSale(creditProduct()));

    $response->assertCreated();
    $response->assertJsonPath('data.borrower_name', 'Maria Santos');
    expect(Creditor::query()->count())->toBe(1);
    expect($response->json('data.creditor_id'))->toBe(Creditor::query()->value('id'));
});

test('a later credit sale can select the saved customer', function () {
    $product = creditProduct();
    $first = $this->postJson('/api/flutter/sales', creditSale($product));
    $first->assertCreated();
    $creditorId = $first->json('data.creditor_id');

    $again = $this->postJson('/api/flutter/sales', [
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_type' => 'sack',
        'payment_method' => 'utang',
        'creditor_id' => $creditorId,
        'due_date' => '2026-11-01',
        'sale_number' => 'SAL-CREDIT-2',
        'idempotency_key' => 'credit-customer-2',
    ]);

    $again->assertCreated();
    $again->assertJsonPath('data.borrower_name', 'Maria Santos');
    $again->assertJsonPath('data.borrower_phone', '09171234567');
    $again->assertJsonPath('data.creditor_id', $creditorId);
    expect($again->json('data.due_date'))->toStartWith('2026-11-01');
    expect(Creditor::query()->count())->toBe(1);

    $list = $this->getJson('/api/flutter/creditors?branch_id=1');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(1);
    expect($list->json('data.0.name'))->toBe('Maria Santos');
    expect($list->json('data.0.phone'))->toBe('09171234567');
});

test('the same phone number stays one customer even when the name is typed again', function () {
    $product = creditProduct();
    $this->postJson('/api/flutter/sales', creditSale($product))->assertCreated();

    $repeat = $this->postJson('/api/flutter/sales', creditSale($product, [
        'borrower_name' => 'Maria S.',
        'borrower_phone' => '0917-123-4567',
        'sale_number' => 'SAL-CREDIT-3',
        'idempotency_key' => 'credit-customer-3',
        'due_date' => '2026-12-01',
    ]));

    $repeat->assertCreated();
    $repeat->assertJsonPath('data.borrower_name', 'Maria Santos');
    expect(Creditor::query()->count())->toBe(1);
});

test('the customer list requires a branch, user, or login', function () {
    $this->getJson('/api/flutter/creditors')->assertUnauthorized();
});
