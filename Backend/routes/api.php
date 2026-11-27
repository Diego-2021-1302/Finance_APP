<?php

use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\CalendarController;
use App\Http\Controllers\Api\V1\CommitmentController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\DebtController;
use App\Http\Controllers\Api\V1\FinanceInsightsController;
use App\Http\Controllers\Api\V1\IncomeController;
use App\Http\Controllers\Api\V1\InterestCutController;
use App\Http\Controllers\Api\V1\PaymentReversalController;
use App\Http\Controllers\Api\V1\ReportController;
use App\Http\Controllers\Api\V1\SettingsController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\ChatController;
use App\Http\Controllers\Api\V1\ExpenseController;
use App\Http\Controllers\Api\V1\TransferController;
use App\Http\Controllers\Api\V1\BudgetReserveController;
use App\Http\Controllers\Api\V1\StatementImportController;
use App\Http\Controllers\Api\V1\PaymentPriorityController;
use App\Http\Controllers\Api\V1\FinancialPlanController;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

$healthHandler = function () {
    try {
        DB::select('SELECT 1');
        return response()->json(['status' => 'healthy', 'services' => ['database' => 'healthy']]);
    } catch (Throwable $exception) {
        report($exception);
        return response()->json(['status' => 'unhealthy', 'services' => ['database' => 'unhealthy']], 503);
    }
};

Route::get('/health', $healthHandler);
Route::get('/api/health', $healthHandler);

use App\Http\Controllers\Api\V1\TunnelController;

Route::get('/openapi.yaml', fn () => response()->file(base_path('openapi.yaml'), ['Content-Type' => 'application/yaml']));

Route::prefix('v1')->name('api.v1.')->group(function () use ($healthHandler) {
    Route::get('/health', $healthHandler)->name('health');
    Route::get('/tunnel/active', [TunnelController::class, 'show'])->name('tunnel.active');
    Route::post('/tunnel/register', [TunnelController::class, 'store'])->name('tunnel.register');
    Route::get('/settings/photos/{user}/{type}', [SettingsController::class, 'photo'])
        ->whereNumber('user')
        ->where('type', 'profile|piggy-bank')
        ->middleware('signed:relative')
        ->name('settings.photos.show');
});

Route::prefix('v1/auth')->name('api.v1.auth.')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth')->name('register');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth')->name('login');
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'current'])->name('current');
        Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    });
});

Route::prefix('v1')->name('api.v1.')->middleware('auth:sanctum')->group(function () {
    Route::post('/insights/recommendations', [FinanceInsightsController::class, 'recommendations'])->middleware('throttle:ai-insights')->name('insights.recommendations');
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');
    Route::get('/dashboard/debt-history', [DashboardController::class, 'debtHistory'])->name('dashboard.debt-history');
    Route::get('/incomes', [IncomeController::class, 'index'])->name('incomes.index');
    Route::post('/incomes', [IncomeController::class, 'store'])->name('incomes.store');
    Route::put('/incomes/{income}', [IncomeController::class, 'update'])->whereNumber('income')->name('incomes.update');
    Route::delete('/incomes/{income}', [IncomeController::class, 'destroy'])->whereNumber('income')->name('incomes.destroy');
    Route::post('/incomes/{income}/receipts', [IncomeController::class, 'receipt'])->whereNumber('income')->name('incomes.receipts.store');

    Route::get('/commitments', [CommitmentController::class, 'index'])->name('commitments.index');
    Route::post('/commitments', [CommitmentController::class, 'store'])->name('commitments.store');
    Route::put('/commitments/{commitment}', [CommitmentController::class, 'update'])->whereNumber('commitment')->name('commitments.update');
    Route::delete('/commitments/{commitment}', [CommitmentController::class, 'destroy'])->whereNumber('commitment')->name('commitments.destroy');
    Route::post('/commitments/{commitment}/archive', [CommitmentController::class, 'pause'])->whereNumber('commitment')->name('commitments.archive');
Route::post('/commitments/{commitment}/pause', [CommitmentController::class, 'pause'])->whereNumber('commitment')->name('commitments.pause');
    Route::post('/commitments/{commitment}/occurrences/{occurrence}/payments', [CommitmentController::class, 'payment'])->whereNumber(['commitment', 'occurrence'])->name('commitments.payments.store');
    Route::post('/payments/{payment}/reversals', [PaymentReversalController::class, 'store'])->whereNumber('payment')->name('payments.reversals.store');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->whereNumber('account')->name('accounts.update');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->whereNumber('account')->name('accounts.destroy');
    Route::post('/accounts/{account}/archive', [AccountController::class, 'archive'])->whereNumber('account')->name('accounts.archive');
    Route::post('/accounts/{account}/deposit', [AccountController::class, 'deposit'])->whereNumber('account')->name('accounts.deposit');
    Route::post('/accounts/{account}/reconcile', [AccountController::class, 'reconcile'])->whereNumber('account')->name('accounts.reconcile');

    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->whereNumber('expense')->name('expenses.update');
    Route::delete('/expenses/{expense}', [ExpenseController::class, 'destroy'])->whereNumber('expense')->name('expenses.destroy');
    Route::get('/transfers', [TransferController::class, 'index'])->name('transfers.index');
    Route::post('/transfers', [TransferController::class, 'store'])->name('transfers.store');
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/debts', [DebtController::class, 'index'])->name('debts.index');
    Route::post('/debts', [DebtController::class, 'store'])->name('debts.store');
    Route::put('/debts/{debt}', [DebtController::class, 'update'])->whereNumber('debt')->name('debts.update');
    Route::delete('/debts/{debt}', [DebtController::class, 'destroy'])->whereNumber('debt')->name('debts.destroy');
    Route::patch('/debts/{debt}/minimum-payment', [DebtController::class, 'updateMinimumPayment'])->whereNumber('debt')->name('debts.minimum-payment.update');
    Route::get('/debts/history', [DebtController::class, 'history'])->name('debts.history');
    Route::get('/debts/{debt}/statements', [DebtController::class, 'statements'])->whereNumber('debt')->name('debts.statements');
    Route::post('/debts/{debt}/increases', [DebtController::class, 'increase'])->whereNumber('debt')->name('debts.increases.store');
    Route::post('/debts/{debt}/payments', [DebtController::class, 'payment'])->whereNumber('debt')->name('debts.payments.store');
    Route::patch('/debts/{debt}/card-limits', [DebtController::class, 'updateCardLimits'])->whereNumber('debt')->name('debts.card-limits.update');
    Route::post('/debts/{debt}/authorize-card', [DebtController::class, 'authorizeCardPreview'])->whereNumber('debt')->name('debts.card-authorize.preview');
    Route::get('/calendar', [CalendarController::class, 'index'])->name('calendar.index');
    Route::get('/calendar/summary', [CalendarController::class, 'summary'])->name('calendar.summary');
    Route::get('/financial-plan', [FinancialPlanController::class, 'index'])->name('financial-plan.index');
    Route::get('/interests/cut', [InterestCutController::class, 'index'])->name('interests.cut');
    Route::get('/priorities', [PaymentPriorityController::class, 'index'])->name('priorities.index');
    Route::get('/reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');
    Route::get('/reports/projection', [ReportController::class, 'projection'])->name('reports.projection');
    Route::get('/settings', [SettingsController::class, 'show'])->name('settings.show');
    Route::patch('/settings', [SettingsController::class, 'update'])->name('settings.update');
    Route::post('/settings/photos', [SettingsController::class, 'uploadPhoto'])->name('settings.photos.upload');

    Route::get('/chat/messages', [ChatController::class, 'index'])->name('chat.index');
    Route::post('/chat/messages', [ChatController::class, 'store'])->name('chat.store');
    Route::delete('/chat/messages', [ChatController::class, 'clear'])->name('chat.clear');

    Route::get('/budgets', [BudgetReserveController::class, 'indexBudgets'])->name('budgets.index');
    Route::post('/budgets', [BudgetReserveController::class, 'storeBudget'])->name('budgets.store');
    Route::get('/reserves', [BudgetReserveController::class, 'indexReserves'])->name('reserves.index');
    Route::post('/reserves', [BudgetReserveController::class, 'storeReserve'])->name('reserves.store');
    Route::patch('/reserves/{reserve}', [BudgetReserveController::class, 'updateReserve'])->whereNumber('reserve')->name('reserves.update');
    Route::delete('/reserves/{reserve}', [BudgetReserveController::class, 'destroyReserve'])->whereNumber('reserve')->name('reserves.destroy');
    Route::get('/imports', [StatementImportController::class, 'index'])->name('imports.index');
    Route::post('/imports', [StatementImportController::class, 'store'])->name('imports.store');
    Route::patch('/imports/{importedTransaction}', [StatementImportController::class, 'updateStatus'])->whereNumber('importedTransaction')->name('imports.update-status');

    // Alcancía / Piggy Bank Routes
    Route::get('/piggy-bank', [\App\Http\Controllers\Api\V1\PiggyBankController::class, 'index'])->name('piggy-bank.index');
    Route::post('/piggy-bank/deposits', [\App\Http\Controllers\Api\V1\PiggyBankController::class, 'store'])->name('piggy-bank.deposits.store');
    Route::post('/piggy-bank/reset', [\App\Http\Controllers\Api\V1\PiggyBankController::class, 'reset'])->name('piggy-bank.reset');
});
