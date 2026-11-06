<?php

declare(strict_types=1);

use App\Modules\Procurement\Http\Controllers\PurchaseRequestController;
use App\Modules\Procurement\Http\Controllers\SubscriptionController;
use App\Modules\Procurement\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

/** M10 Procurement & subscriptions. Policy-checked in the controllers. @see SRS M10-01 … M10-05 */
Route::middleware(['web', 'app'])->prefix('procurement')->name('procurement.')->group(function (): void {
    Route::get('vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::post('vendors', [VendorController::class, 'store'])->name('vendors.store');
    Route::get('vendors/{vendor}', [VendorController::class, 'show'])->name('vendors.show');
    Route::put('vendors/{vendor}', [VendorController::class, 'update'])->name('vendors.update');

    Route::get('requests', [PurchaseRequestController::class, 'index'])->name('requests.index');
    Route::post('requests', [PurchaseRequestController::class, 'store'])->name('requests.store');
    Route::get('requests/{purchaseRequest}', [PurchaseRequestController::class, 'show'])->name('requests.show');
    Route::put('requests/{purchaseRequest}', [PurchaseRequestController::class, 'update'])->name('requests.update');
    Route::post('requests/{purchaseRequest}/submit', [PurchaseRequestController::class, 'submit'])->name('requests.submit');
    Route::post('requests/{purchaseRequest}/approve', [PurchaseRequestController::class, 'approve'])->name('requests.approve');
    Route::post('requests/{purchaseRequest}/reject', [PurchaseRequestController::class, 'reject'])->name('requests.reject');
    Route::post('requests/{purchaseRequest}/cancel', [PurchaseRequestController::class, 'cancel'])->name('requests.cancel');
    Route::post('requests/{purchaseRequest}/order', [PurchaseRequestController::class, 'order'])->name('requests.order');
    Route::post('receipts', [PurchaseRequestController::class, 'receive'])->name('receipts.store');

    Route::get('subscriptions', [SubscriptionController::class, 'index'])->name('subscriptions.index');
    Route::post('subscriptions', [SubscriptionController::class, 'store'])->name('subscriptions.store');
    Route::put('subscriptions/{subscription}', [SubscriptionController::class, 'update'])->name('subscriptions.update');
    Route::post('subscriptions/{subscription}/link', [SubscriptionController::class, 'linkFinance'])->name('subscriptions.link');
    Route::post('subscriptions/{subscription}/cancel', [SubscriptionController::class, 'cancel'])->name('subscriptions.cancel');
    Route::post('subscription-reviews/{review}/decide', [SubscriptionController::class, 'decide'])->name('subscription-reviews.decide');
});
