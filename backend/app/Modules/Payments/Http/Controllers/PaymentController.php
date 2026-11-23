<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Listing;
use App\Modules\Identity\Tenancy\Actor;
use App\Modules\Payments\Enums\PaymentStatus;
use App\Modules\Payments\Http\Requests\RecordPaymentRequest;
use App\Modules\Payments\Http\Requests\VoidPaymentRequest;
use App\Modules\Payments\Http\Resources\PaymentResource;
use App\Modules\Payments\Models\Payment;
use App\Modules\Payments\Services\RecordPayment;
use App\Modules\Payments\Services\VoidPayment;
use App\Modules\Reference\Enums\PaymentMeans;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

/**
 * Payments received from customers, applied to their accepted invoices. A payment is recorded,
 * and voided with a reason; it is never edited or deleted.
 *
 * @phpstan-import-type PaymentInput from RecordPayment
 */
final class PaymentController
{
    /**
     * List payments.
     *
     * Newest first. Filters: ?contact_id=, ?from= and ?to= (the payment date), ?payment_means_code=,
     * ?status=recorded|voided and ?search= (the number, the customer or the reference).
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var array{contact_id?: string|null, payment_means_code?: string|null, status?: string|null, from?: string|null, to?: string|null} $filters */
        $filters = $request->validate([
            'contact_id' => ['sometimes', 'nullable', 'string'],
            'payment_means_code' => ['sometimes', 'nullable', Rule::enum(PaymentMeans::class)],
            'status' => ['sometimes', 'nullable', Rule::enum(PaymentStatus::class)],
            'from' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = Payment::query()
            ->when($filters['contact_id'] ?? null, fn (Builder $query, string $id) => $query->where('contact_id', $id))
            ->when($filters['payment_means_code'] ?? null, fn (Builder $query, string $code) => $query->where('payment_means_code', $code))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where('payment_date', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where('payment_date', '<=', $to))
            ->orderByDesc('payment_date')
            ->orderByDesc('number');

        return PaymentResource::collection(Listing::paginate($query, $request, [], function (Builder $query, string $pattern): void {
            $query->where('full_number', 'like', $pattern)
                ->orWhere('customer_name', 'like', $pattern)
                ->orWhere('customer_identification', 'like', $pattern)
                ->orWhere('reference', 'like', $pattern);
        }));
    }

    /**
     * Record a payment.
     *
     * The payment is applied to invoices of its customer: 201 with the payment. 422 when an amount is
     * above what is left to collect on its invoice, or when the invoice is not an accepted invoice of
     * that customer.
     */
    public function store(RecordPaymentRequest $request, RecordPayment $record): JsonResponse
    {
        /** @var PaymentInput $input */
        $input = $request->validated();
        $payment = $record($input, Actor::user($request));

        return PaymentResource::make($payment->load(Payment::DETAILS))->response()->setStatusCode(201);
    }

    /**
     * Show a payment.
     *
     * The payment with the invoices it was applied to, and its timeline.
     */
    public function show(Payment $payment): PaymentResource
    {
        return PaymentResource::make($payment->load(Payment::DETAILS));
    }

    /**
     * Void a payment.
     *
     * The void needs a reason, and the invoices get their balance back. 409 when the payment is
     * already voided. Owners, admins and read_write API keys only.
     */
    public function void(VoidPaymentRequest $request, Payment $payment, VoidPayment $void): PaymentResource
    {
        return PaymentResource::make($void($payment->id, $request->safe()->string('reason')->toString(), Actor::user($request))->load(Payment::DETAILS));
    }
}
