<?php

use App\Models\Product;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function utangJpegBytes(): string
{
    $bytes = base64_decode(
        '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=',
        true,
    );

    if (!is_string($bytes) || !str_starts_with($bytes, "\xFF\xD8\xFF")) {
        throw new RuntimeException('Test JPEG is not valid.');
    }

    return $bytes;
}

function utangProduct(): Product
{
    return Product::query()->create([
        'name' => 'Rice',
        'unit' => 'sack',
        'price' => 100,
    ]);
}

function utangSalePayload(Product $product, array $overrides = []): array
{
    return array_merge([
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_type' => 'sack',
        'payment_method' => 'utang',
        'idempotency_key' => 'local-sale-1-item-1',
        'sale_number' => 'SAL-UTANG-1',
        'borrower_name' => 'Maria Santos',
        'due_date' => '2026-10-20',
    ], $overrides);
}

test('a multipart utang sale stores the phone and one valid id image', function () {
    Storage::fake('public');

    $product = utangProduct();
    $jpeg = utangJpegBytes();
    $encoded = base64_encode($jpeg);

    $response = $this->post('/api/flutter/sales', utangSalePayload($product, [
        'borrower_phone' => '0917-123-4567',
        'valid_id' => UploadedFile::fake()->createWithContent('valid-id.jpg', $jpeg),
        'valid_id_image' => $encoded,
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.borrower_phone', '0917-123-4567');
    $response->assertJsonPath('data.borrower_name', 'Maria Santos');
    expect($response->json('data.due_date'))->toStartWith('2026-10-20');
    expect($response->json('data.valid_id_url'))->toStartWith('/storage/utang-ids/');
    expect($response->getContent())->not->toContain($encoded);

    $sale = Sale::query()->first();
    expect($sale)->not->toBeNull();
    expect(Storage::disk('public')->get($sale->valid_id_path))->toBe($jpeg);
    expect(Storage::disk('public')->allFiles('utang-ids'))->toHaveCount(1);

    $second = $this->post('/api/flutter/sales', utangSalePayload($product, [
        'idempotency_key' => 'local-sale-1-item-2',
        'borrower_phone' => '09171234567',
        'valid_id' => UploadedFile::fake()->createWithContent('valid-id.jpg', $jpeg),
        'valid_id_image' => $encoded,
    ]));

    $second->assertCreated();
    expect(Sale::query()->count())->toBe(2);
    expect(Sale::query()->pluck('valid_id_path')->unique())->toHaveCount(1);
    expect(Storage::disk('public')->allFiles('utang-ids'))->toHaveCount(1);

    $replay = $this->post('/api/flutter/sales', utangSalePayload($product, [
        'borrower_phone' => '0917-123-4567',
        'valid_id' => UploadedFile::fake()->createWithContent('valid-id.jpg', $jpeg),
        'valid_id_image' => $encoded,
    ]));

    $replay->assertOk();
    expect(Sale::query()->count())->toBe(2);
    expect(Storage::disk('public')->allFiles('utang-ids'))->toHaveCount(1);

    $list = $this->getJson('/api/utang');
    $list->assertOk();
    expect($list->json('data.0.valid_id_url'))->toStartWith('/storage/utang-ids/');
    expect($list->getContent())->not->toContain($encoded);
});

test('a json utang sale can store only a phone number', function () {
    Storage::fake('public');

    $response = $this->postJson('/api/flutter/sales', utangSalePayload(utangProduct(), [
        'borrower_phone' => '09171234567',
        'sale_number' => 'SAL-PHONE-1',
        'idempotency_key' => 'local-sale-phone-1',
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.borrower_phone', '09171234567');
    $response->assertJsonPath('data.valid_id_url', null);
    expect(Storage::disk('public')->allFiles('utang-ids'))->toHaveCount(0);
});

test('a missing valid id file can be stored from the base64 field', function () {
    Storage::fake('public');

    $jpeg = utangJpegBytes();

    $response = $this->post('/api/flutter/sales', utangSalePayload(utangProduct(), [
        'sale_number' => 'SAL-B64-1',
        'idempotency_key' => 'local-sale-b64-1',
        'valid_id_image' => 'data:image/jpeg;base64,'.base64_encode($jpeg),
    ]));

    $response->assertCreated();
    $response->assertJsonPath('data.borrower_phone', null);

    $sale = Sale::query()->first();
    expect(Storage::disk('public')->get($sale->valid_id_path))->toBe($jpeg);
});

test('cash sales do not require a borrower phone or valid id', function () {
    Storage::fake('public');

    $response = $this->postJson('/api/flutter/sales', [
        'product_id' => utangProduct()->id,
        'quantity' => 1,
        'unit_type' => 'sack',
        'payment_method' => 'cash',
        'idempotency_key' => 'local-cash-1',
        'sale_number' => 'SAL-CASH-1',
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.payment_method', 'cash');
    $response->assertJsonPath('data.borrower_phone', null);
    $response->assertJsonPath('data.valid_id_url', null);
});

test('utang without a phone number or valid id is rejected', function () {
    Storage::fake('public');

    $response = $this->postJson('/api/flutter/sales', utangSalePayload(utangProduct(), [
        'sale_number' => 'SAL-NONE-1',
        'idempotency_key' => 'local-sale-none-1',
    ]));

    $response->assertStatus(422);
    expect(Sale::query()->count())->toBe(0);
});

test('a borrower phone with fewer than 10 digits is rejected', function () {
    Storage::fake('public');

    $response = $this->postJson('/api/flutter/sales', utangSalePayload(utangProduct(), [
        'borrower_phone' => '0917',
        'sale_number' => 'SAL-SHORT-1',
        'idempotency_key' => 'local-sale-short-1',
    ]));

    $response->assertStatus(422);
    expect(Sale::query()->count())->toBe(0);
});
