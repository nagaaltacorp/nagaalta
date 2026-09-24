<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BranchController;
use App\Http\Controllers\Api\DailySalesReportController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DiscountSetupController;
use App\Http\Controllers\Api\EmployeeController;
use App\Http\Controllers\Api\InventoryController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProductVatController;
use App\Http\Controllers\Api\ProductRetailController;
use App\Http\Controllers\Api\ProductReplacementController;
use App\Http\Controllers\Api\ProductSellingController;
use App\Http\Controllers\Api\ProductSyncController;
use App\Http\Controllers\Api\SaleController;
use App\Http\Controllers\Api\SettingsController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\UtangController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login']);
Route::post('/flutter/account', [AuthController::class, 'updateAccount'])
    ->middleware('throttle:8,1');

Route::get('/flutter/products', [ProductController::class, 'index']);
Route::get('/flutter/selling', [ProductSellingController::class, 'index']);
Route::get('/flutter/products/wholesale', [ProductSellingController::class, 'wholesale']);
Route::get('/flutter/products/retail', [ProductSellingController::class, 'retail']);
Route::get('/flutter/discounts', [DiscountSetupController::class, 'flutterIndex']);
Route::post('/flutter/discounts/unlock', [DiscountSetupController::class, 'flutterUnlock'])
    ->middleware('throttle:8,1');
Route::get('/flutter/replacements/settings', [ProductReplacementController::class, 'flutterSettings']);
Route::get('/flutter/replacements/receipt', [ProductReplacementController::class, 'flutterReceipt']);
Route::post('/flutter/replacements/quote', [ProductReplacementController::class, 'flutterQuote']);
Route::post('/flutter/replacements', [ProductReplacementController::class, 'flutterStore']);
Route::get('/flutter/daily-sales-reports/preview', [DailySalesReportController::class, 'flutterPreview']);
Route::get('/flutter/daily-sales-reports', [DailySalesReportController::class, 'flutterIndex']);
Route::post('/flutter/daily-sales-reports', [DailySalesReportController::class, 'flutterStore']);
Route::get('/flutter/sales', [SaleController::class, 'historyFromFlutter']);
Route::post('/flutter/sales', [SaleController::class, 'storeFromFlutter']);
Route::get('/flutter/utang', [UtangController::class, 'flutterIndex']);
Route::post('/flutter/utang/pay', [UtangController::class, 'markPaid']);

Route::post('/products', [ProductController::class, 'store']);
Route::put('/products/{id}', [ProductController::class, 'update']);
Route::delete('/products/{id}', [ProductController::class, 'destroy']);

Route::post('/sales', [SaleController::class, 'store']);

Route::middleware('web')->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index']);
    Route::get('/sales', [SaleController::class, 'index']);
    Route::get('/sales/export/{format}', [SaleController::class, 'export']);
    Route::get('/utang', [UtangController::class, 'index']);
    Route::post('/utang/pay', [UtangController::class, 'markPaid']);
    Route::get('/daily-sales-reports', [DailySalesReportController::class, 'index']);
    Route::get('/daily-sales-reports/{id}', [DailySalesReportController::class, 'show']);
    Route::get('/daily-sales-reports/{id}/pdf', [DailySalesReportController::class, 'pdf']);

    Route::get('/branches', [BranchController::class, 'index']);
    Route::get('/products', [ProductController::class, 'index']);
    Route::get('/inventories', [InventoryController::class, 'index']);
    Route::get('/inventories/revenue-logs', [InventoryController::class, 'revenueLogs']);
    Route::get('/inventories/main', [InventoryController::class, 'mainIndex']);
    Route::post('/inventories/main', [InventoryController::class, 'storeMain']);
    Route::put('/inventories/main/{id}', [InventoryController::class, 'updateMain']);
    Route::delete('/inventories/main/{id}', [InventoryController::class, 'destroyMain']);
    Route::put('/inventories/{id}', [InventoryController::class, 'update']);
    Route::delete('/inventories/{id}', [InventoryController::class, 'destroy']);
    Route::post('/inventories', [InventoryController::class, 'store']);

    Route::middleware('admin')->group(function () {
        Route::get('/employees', [EmployeeController::class, 'index']);
        Route::post('/employees', [EmployeeController::class, 'store']);
        Route::put('/employees/{id}', [EmployeeController::class, 'update']);
        Route::delete('/employees/{id}', [EmployeeController::class, 'destroy']);

        Route::get('/branches/manager-options', [BranchController::class, 'managerOptions']);
        Route::post('/branches', [BranchController::class, 'store']);
        Route::put('/branches/{id}', [BranchController::class, 'update']);
        Route::delete('/branches/{id}', [BranchController::class, 'destroy']);

        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);

        Route::get('/settings', [SettingsController::class, 'show']);
        Route::put('/settings', [SettingsController::class, 'update']);
        Route::put('/product-vat', [ProductVatController::class, 'update']);
        Route::put('/product-retail', [ProductRetailController::class, 'update']);

        Route::get('/discount-setup', [DiscountSetupController::class, 'show']);
        Route::put('/discount-setup/password', [DiscountSetupController::class, 'updatePassword']);
        Route::post('/discount-setup/options', [DiscountSetupController::class, 'storeOption']);
        Route::put('/discount-setup/options/{id}', [DiscountSetupController::class, 'updateOption']);
        Route::delete('/discount-setup/options/{id}', [DiscountSetupController::class, 'destroyOption']);

        Route::get('/replacement-setup', [ProductReplacementController::class, 'show']);
        Route::put('/replacement-setup', [ProductReplacementController::class, 'update']);
    });
});

Route::post('/products/sync', [ProductSyncController::class, 'sync']);
