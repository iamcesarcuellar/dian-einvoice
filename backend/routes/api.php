<?php

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Every route here is served under /api/v1 (see bootstrap/app.php).

Route::get('health', HealthController::class)->name('health');

// Each module owns its route file.
require app_path('Modules/Reference/routes.php');
require app_path('Modules/Identity/routes.php');
require app_path('Modules/Catalog/routes.php');
require app_path('Modules/Billing/routes.php');
require app_path('Modules/Dian/routes.php');
require app_path('Modules/Delivery/routes.php');
