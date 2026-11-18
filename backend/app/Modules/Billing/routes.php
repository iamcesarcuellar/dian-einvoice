<?php

use App\Http\ApiMiddleware;
use App\Modules\Billing\Enums\DocumentType;
use App\Modules\Billing\Http\Controllers\DocumentController;
use App\Modules\Billing\Http\Controllers\NumberTemplateController;
use Illuminate\Support\Facades\Route;

// Tenant routes of the public API: the web app session (X-Company-Id) or an API key. Every member
// and every key can read.
Route::middleware(ApiMiddleware::TENANT)->group(function (): void {
    // /invoices, /credit-notes and /debit-notes: the same operations; the route name gives the type.
    foreach (DocumentType::cases() as $type) {
        $path = $type->path();

        Route::get($path, [DocumentController::class, 'index'])->name("{$path}.index");

        if ($type->isNote()) {
            // Before {document}, so "prefill" is never read as an id.
            Route::get("{$path}/prefill", [DocumentController::class, 'prefill'])->middleware('permission:manage-documents')->name("{$path}.prefill");
        }

        Route::get("{$path}/{document}", [DocumentController::class, 'show'])->name("{$path}.show");
        Route::get("{$path}/{document}/xml", [DocumentController::class, 'xml'])->name("{$path}.xml");
        Route::get("{$path}/{document}/dian-response", [DocumentController::class, 'dianResponse'])->name("{$path}.dian-response");

        // Members, admins, owners and read_write keys write drafts, issue them and act on their
        // electronic document chain.
        Route::middleware('permission:manage-documents')->group(function () use ($path): void {
            Route::post("{$path}/preview", [DocumentController::class, 'preview'])->name("{$path}.preview");
            Route::post($path, [DocumentController::class, 'store'])->name("{$path}.store");
            Route::patch("{$path}/{document}", [DocumentController::class, 'update'])->name("{$path}.update");
            Route::delete("{$path}/{document}", [DocumentController::class, 'destroy'])->name("{$path}.destroy");
            Route::post("{$path}/{document}/issue", [DocumentController::class, 'issue'])->name("{$path}.issue");
            Route::post("{$path}/{document}/retry", [DocumentController::class, 'retry'])->name("{$path}.retry");
            Route::post("{$path}/{document}/resend", [DocumentController::class, 'resend'])->name("{$path}.resend");
        });
    }

    Route::get('number-templates', [NumberTemplateController::class, 'index'])->name('number-templates.index');
    Route::get('number-templates/{numberTemplate}', [NumberTemplateController::class, 'show'])->name('number-templates.show');

    // Only admins and owners manage numbering, in the web app.
    Route::middleware('permission:manage-settings')->group(function (): void {
        Route::post('number-templates', [NumberTemplateController::class, 'store'])->name('number-templates.store');
        Route::patch('number-templates/{numberTemplate}', [NumberTemplateController::class, 'update'])->name('number-templates.update');
        Route::delete('number-templates/{numberTemplate}', [NumberTemplateController::class, 'destroy'])->name('number-templates.destroy');
    });
});
