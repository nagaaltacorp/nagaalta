<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Support\InertiaAdminPage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
	if (Auth::check()) {
		return redirect('/dashboard');
	}

	return Inertia::render('Landing', [
		'turnstileSiteKey' => config('services.turnstile.site_key'),
	]);
})->name('login');

Route::middleware('guest')->group(function () {
	Route::post('/login', [AuthenticatedSessionController::class, 'store'])->name('login.store');
});

Route::middleware(['auth', 'web.access'])->group(function () {
	Route::get('/dashboard', fn () => Inertia::render('Dashboard'));
	Route::get('/products', fn () => Inertia::render('Products'));
	Route::get('/product-vat', fn () => InertiaAdminPage::render('ProductVat', 'Product VAT'));
	Route::get('/product-retail', fn () => InertiaAdminPage::render('ProductRetail', 'Retail Setup'));
	Route::get('/inventories', fn () => Inertia::render('Inventories'));
	Route::get('/main-inventory', fn () => Inertia::render('MainInventory'));
	Route::get('/sales-report', fn () => Inertia::render('SalesReport'));
	Route::get('/utang', fn () => Inertia::render('Utang'));
	Route::get('/daily-cashier-reports', fn () => Inertia::render('DailyCashierReports'));
	Route::get('/discount-setup', fn () => InertiaAdminPage::render('DiscountSetup', 'Discount Setup'));
	Route::get('/product-replacement', fn () => InertiaAdminPage::render('ProductReplacement', 'Product Replacement'));
	Route::get('/product-profit-analysis', fn () => Inertia::render('ProductProfitAnalysis'));
	Route::get('/employees', fn () => InertiaAdminPage::render('Employees', 'Employees'));
	Route::get('/branches', fn () => InertiaAdminPage::render('Branches', 'Branches'));
	Route::get('/users', fn () => InertiaAdminPage::render('Users', 'Users'));
	Route::get('/settings', fn () => InertiaAdminPage::render('Settings', 'Settings'));

	Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
});
