<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PaymentController extends Controller
{
    protected function xenditHeaders()
    {
        $secretKey = config('services.xendit.secret_key');
        return [
            'Authorization' => 'Basic ' . base64_encode($secretKey . ':'),
            'Content-Type'  => 'application/json',
        ];
    }

    /**
     * CREATE A XENDIT INVOICE for a reservation's fee (sandbox/test mode)
     */
    public function createIntent(Request $request, $reservationId)
    {
        try {
            $user = auth()->user();
            $reservation = Reservation::with(['listing', 'tenant'])->findOrFail($reservationId);

            if ($reservation->tenant_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            $fee = $reservation->listing->reservation_fee;

            if (!$fee || $fee <= 0) {
                return response()->json(['success' => false, 'message' => 'This listing does not require a reservation fee'], 422);
            }

            // Xendit uses whole currency units for PHP (not centavos, unlike PayMongo)
            $frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:5173'), '/');

            $res = Http::withHeaders($this->xenditHeaders())
                ->post('https://api.xendit.co/v2/invoices', [
                    'external_id'          => 'tierhome-reservation-' . $reservation->id . '-' . time(),
                    'amount'               => (float) $fee,
                    'currency'             => 'PHP',
                    'description'          => "TierHome Reservation Fee — {$reservation->listing->title}",
                    'invoice_duration'     => 3600, // 1 hour to complete payment
                    'customer'             => [
                        'given_names' => $user->first_name,
                        'surname'     => $user->last_name,
                        'email'       => $user->email,
                    ],
                    'success_redirect_url' => "{$frontendUrl}/listing/{$reservation->listing_id}?payment_return=1&reservation_id={$reservation->id}",
                    'failure_redirect_url' => "{$frontendUrl}/listing/{$reservation->listing_id}?payment_return=1&reservation_id={$reservation->id}&failed=1",
                ]);

            if (!$res->successful()) {
                Log::warning('Xendit create invoice failed: ' . $res->body());
                return response()->json(['success' => false, 'message' => 'Failed to initiate payment. Please try again.'], 500);
            }

            $data = $res->json();

            $reservation->update([
                'payment_status' => 'pending',
                'paymongo_payment_intent_id' => $data['id'], // reused column, now stores Xendit invoice id
            ]);

            return response()->json([
                'success' => true,
                'data' => [
                    'invoice_id'  => $data['id'],
                    'invoice_url' => $data['invoice_url'],
                    'amount'      => $fee,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('Xendit createIntent exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Something went wrong initiating payment.'], 500);
        }
    }

    /**
     * CONFIRM PAYMENT STATUS — called by frontend after Xendit redirect
     * Polls Xendit directly to confirm the actual status rather than trusting the frontend.
     */
    public function confirmPayment(Request $request, $reservationId)
    {
        try {
            $user = auth()->user();
            $reservation = Reservation::with('listing')->findOrFail($reservationId);

            if ($reservation->tenant_id !== $user->id) {
                return response()->json(['success' => false, 'message' => 'Access denied'], 403);
            }

            if (!$reservation->paymongo_payment_intent_id) {
                return response()->json(['success' => false, 'message' => 'No payment found for this reservation'], 422);
            }

            $res = Http::withHeaders($this->xenditHeaders())
                ->get("https://api.xendit.co/v2/invoices/{$reservation->paymongo_payment_intent_id}");

            if (!$res->successful()) {
                return response()->json(['success' => false, 'message' => 'Failed to verify payment status.'], 500);
            }

            $status = $res->json()['status']; // PENDING | PAID | EXPIRED

            if ($status === 'PAID' || $status === 'SETTLED') {
                $reservation->update([
                    'payment_status' => 'paid',
                    'paid_at' => now(),
                ]);

                // ✅ This is now the actual "new reservation" notification for fee-required listings —
                // fires once payment is confirmed, not when the reservation is first created (avoids
                // notifying landlords about abandoned/never-paid attempts)
                \App\Models\Notification::notify(
                    $reservation->listing->landlord_id,
                    'new_reservation',
                    'New Reservation Request',
                    "{$reservation->tenant->first_name} reserved \"{$reservation->listing->title}\" and paid the reservation fee.",
                    '/landlord/reservations'
                );

                return response()->json(['success' => true, 'data' => ['payment_status' => 'paid']]);
            }

            return response()->json(['success' => true, 'data' => ['payment_status' => strtolower($status)]]);

        } catch (\Exception $e) {
            Log::error('Xendit confirmPayment exception: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => 'Something went wrong confirming payment.'], 500);
        }
    }

    /**
     * PROCESS REFUND — called automatically when a paid reservation is cancelled/rejected
     */
    public function refund(Reservation $reservation)
    {
        try {
            if ($reservation->payment_status !== 'paid') {
                return; // nothing to refund
            }

            $fee = $reservation->listing->reservation_fee;

            $reservation->update(['payment_status' => 'refund_processing']);

            $refundRes = Http::withHeaders($this->xenditHeaders())
                ->post('https://api.xendit.co/refunds', [
                    'reference_id' => 'tierhome-refund-' . $reservation->id . '-' . time(),
                    'invoice_id'   => $reservation->paymongo_payment_intent_id,
                    'currency'     => 'PHP',
                    'amount'       => (float) $fee,
                    'reason'       => 'CANCELLATION',
                ]);

            if ($refundRes->successful()) {
                $reservation->update([
                    'payment_status' => 'refunded',
                    'refunded_at' => now(),
                    'paymongo_refund_id' => $refundRes->json()['id'],
                ]);
            } else {
                Log::warning('Xendit refund failed: ' . $refundRes->body());
                $reservation->update(['payment_status' => 'refund_failed']);
            }

        } catch (\Exception $e) {
            Log::error('Xendit refund exception: ' . $e->getMessage());
            $reservation->update(['payment_status' => 'refund_failed']);
        }
    }
}