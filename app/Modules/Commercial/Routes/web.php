<?php

declare(strict_types=1);

use App\Modules\Commercial\Http\Controllers\CommercialReportController;
use App\Modules\Commercial\Http\Controllers\DocumentController;
use App\Modules\Commercial\Http\Controllers\ReceiptController;
use App\Modules\Commercial\Http\Controllers\TemplateController;
use Illuminate\Support\Facades\Route;

/**
 * M03 Commercial. Every action is policy-checked in its controller (deny-by-default, CLAUDE.md rule 3).
 * Issuing documents and recording receipts are idempotent (Idempotency-Key, §5.1).
 *
 * @see SRS M03-01 … M03-08
 */
Route::middleware(['web', 'app'])->prefix('commercial')->name('commercial.')->group(function (): void {
    foreach (['quotation' => 'quotations', 'invoice' => 'invoices', 'credit_note' => 'credit-notes'] as $type => $base) {
        Route::prefix($base)->name($base.'.')->group(function () use ($type): void {
            Route::get('/', [DocumentController::class, 'index'])->name('index')->defaults('docType', $type);
            if ($type !== 'credit_note') {
                Route::get('create', [DocumentController::class, 'create'])->name('create')->defaults('docType', $type);
                Route::post('/', [DocumentController::class, 'store'])->name('store')->defaults('docType', $type);
            }
            Route::get('{document}', [DocumentController::class, 'show'])->whereNumber('document')->name('show')->defaults('docType', $type);
            Route::get('{document}/edit', [DocumentController::class, 'edit'])->whereNumber('document')->name('edit')->defaults('docType', $type);
            Route::put('{document}', [DocumentController::class, 'update'])->whereNumber('document')->name('update')->defaults('docType', $type);
            Route::delete('{document}', [DocumentController::class, 'destroy'])->whereNumber('document')->name('destroy')->defaults('docType', $type);
            Route::post('{document}/submit', [DocumentController::class, 'submit'])->whereNumber('document')->name('submit')->defaults('docType', $type);
            Route::post('{document}/approve', [DocumentController::class, 'approve'])->whereNumber('document')->name('approve')->defaults('docType', $type);
            Route::post('{document}/return', [DocumentController::class, 'returnToDraft'])->whereNumber('document')->name('return')->defaults('docType', $type);
            Route::post('{document}/issue', [DocumentController::class, 'issue'])->whereNumber('document')->middleware('idempotent')->name('issue')->defaults('docType', $type);
            Route::post('{document}/void', [DocumentController::class, 'void'])->whereNumber('document')->name('void')->defaults('docType', $type);
            Route::post('{document}/regenerate-pdf', [DocumentController::class, 'regenerate'])->whereNumber('document')->name('regenerate')->defaults('docType', $type);
        });
    }
    Route::post('quotations/{document}/respond', [DocumentController::class, 'respond'])->whereNumber('document')->name('quotations.respond');
    Route::post('quotations/{document}/revise', [DocumentController::class, 'revise'])->whereNumber('document')->name('quotations.revise');
    Route::post('quotations/{document}/convert-invoice', [DocumentController::class, 'convertToInvoice'])->whereNumber('document')->name('quotations.convert-invoice');
    Route::post('quotations/{document}/convert-project', [DocumentController::class, 'convertToProject'])->whereNumber('document')->name('quotations.convert-project');
    Route::post('invoices/{document}/credit-note', [DocumentController::class, 'creditNote'])->whereNumber('document')->name('invoices.credit-note');

    Route::get('receipts', [ReceiptController::class, 'index'])->name('receipts.index');
    Route::get('receipts/create', [ReceiptController::class, 'create'])->name('receipts.create');
    Route::post('receipts', [ReceiptController::class, 'store'])->middleware('idempotent')->name('receipts.store');
    Route::get('receipts/{receipt}', [ReceiptController::class, 'show'])->name('receipts.show');
    Route::post('receipts/{receipt}/void', [ReceiptController::class, 'void'])->name('receipts.void');
    Route::post('receipts/{receipt}/regenerate-pdf', [ReceiptController::class, 'regenerate'])->name('receipts.regenerate');

    Route::get('templates', [TemplateController::class, 'index'])->name('templates.index');
    Route::post('templates/{template}/versions', [TemplateController::class, 'storeVersion'])->name('templates.versions.store');
    Route::get('template-versions/{version}/preview', [TemplateController::class, 'preview'])->name('templates.preview');

    Route::get('reports/{report}', CommercialReportController::class)->whereIn('report', array_keys(CommercialReportController::REPORTS))->name('reports.show');
});
