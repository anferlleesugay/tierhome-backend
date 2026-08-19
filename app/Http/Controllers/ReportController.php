<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\PropertyListing;
use App\Models\Expense;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ReportController extends Controller
{
    public function getPreference()
    {
        $user = auth()->user();
        return response()->json([
            'success' => true,
            'data' => [
                'report_frequency'     => $user->report_frequency ?? 'off',
                'last_report_sent_at'  => $user->last_report_sent_at,
            ],
        ]);
    }

    public function updatePreference(Request $request)
    {
        $validated = $request->validate([
            'report_frequency' => 'required|in:off,weekly,monthly',
        ]);

        auth()->user()->update($validated);

        return response()->json(['success' => true, 'data' => $validated]);
    }

    /**
     * Resolve the ?range= query param into a [start, end] date pair.
     * Supports: this_month, this_year, all_time (default).
     */
    private function resolveRange(Request $request): array
    {
        $range = $request->query('range', 'all_time');

        return match ($range) {
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            'this_year'  => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
            default      => [Carbon::createFromTimestamp(0), Carbon::now()->endOfDay()],
        };
    }

    private function streamCsv(string $filename, array $headerRow, iterable $rows)
    {
        return response()->streamDownload(function () use ($headerRow, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headerRow);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Reservation & Payment History — covers Client 2's "Reservation history",
     * "Payment history", and "Tenant records" asks in one combined export,
     * since every reservation row already carries the tenant's info.
     */
    public function reservationHistory(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);
        $landlordId = auth()->id();
        $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

        $reservations = Reservation::whereIn('listing_id', $listingIds)
            ->whereBetween('created_at', [$start, $end])
            ->with(['tenant:id,first_name,last_name,email,phone_number', 'listing:id,title,reservation_fee'])
            ->orderBy('created_at', 'desc')
            ->get();

        $rows = $reservations->map(function ($r) {
            return [
                $r->created_at?->format('Y-m-d'),
                $r->tenant->first_name ?? '' . ' ' . ($r->tenant->last_name ?? ''),
                $r->tenant->email ?? '',
                $r->tenant->phone_number ?? '',
                $r->listing->title ?? '',
                $r->move_in_date?->format('Y-m-d') ?? '',
                $r->move_out_date?->format('Y-m-d') ?? '',
                ucfirst($r->status),
                ucfirst($r->payment_status ?? 'unpaid'),
                $r->payment_status === 'paid' ? number_format((float) ($r->listing->reservation_fee ?? 0), 2) : '0.00',
                $r->paid_at?->format('Y-m-d') ?? '',
            ];
        });

        $headers = ['Date Requested', 'Tenant Name', 'Email', 'Phone', 'Listing', 'Move-in', 'Move-out', 'Status', 'Payment Status', 'Amount Paid (PHP)', 'Paid Date'];

        return $this->streamCsv('reservation-history-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }

    /**
     * Revenue / Income Summary by month — covers Client 2's "Income/rental reports"
     * and "Annual summaries" (via the ?range=this_year filter).
     */
    public function revenueSummary(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);
        $landlordId = auth()->id();
        $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

        $rows = Reservation::whereIn('reservations.listing_id', $listingIds)
            ->where('payment_status', 'paid')
            ->whereBetween('reservations.paid_at', [$start, $end])
            ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
            ->selectRaw("DATE_FORMAT(reservations.paid_at, '%Y-%m') as month, COUNT(*) as paid_count, SUM(property_listings.reservation_fee) as total_revenue")
            ->groupBy('month')
            ->orderBy('month')
            ->get()
            ->map(fn($r) => [$r->month, $r->paid_count, number_format((float) $r->total_revenue, 2)]);

        $headers = ['Month', 'Paid Reservations', 'Total Revenue (PHP)'];

        return $this->streamCsv('revenue-summary-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }

    /**
     * Listings & Occupancy Report — covers Client 2's "Occupancy reports".
     */
    public function occupancyReport(Request $request)
    {
        $landlordId = auth()->id();

        $listings = PropertyListing::where('landlord_id', $landlordId)
            ->withCount(['reservations as total_reservations'])
            ->withCount(['reservations as approved_reservations' => fn($q) => $q->where('status', 'approved')])
            ->get(['id', 'title', 'barangay', 'price', 'status', 'is_verified']);

        $rows = $listings->map(fn($l) => [
            $l->title,
            $l->barangay ?? '',
            number_format((float) $l->price, 2),
            $l->status === 'unavailable' ? 'Occupied' : ucfirst($l->status),
            $l->is_verified ? 'Yes' : 'No',
            $l->total_reservations,
            $l->approved_reservations,
        ]);

        $headers = ['Listing', 'Barangay', 'Price (PHP/mo)', 'Occupancy Status', 'Verified', 'Total Reservations', 'Approved Reservations'];

        return $this->streamCsv('occupancy-report-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }

    /**
     * Expense Report — the one report from Client 2's Q10 that genuinely didn't
     * exist until Expense Tracking was built.
     */
    public function expenseReport(Request $request)
    {
        [$start, $end] = $this->resolveRange($request);
        $landlordId = auth()->id();
        $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

        $expenses = Expense::whereIn('listing_id', $listingIds)
            ->whereBetween('expense_date', [$start, $end])
            ->with('listing:id,title')
            ->orderBy('expense_date', 'desc')
            ->get();

        $rows = $expenses->map(fn($e) => [
            $e->expense_date->format('Y-m-d'),
            $e->listing->title ?? '',
            $e->category,
            number_format((float) $e->amount, 2),
            $e->description ?? '',
        ]);

        $headers = ['Date', 'Listing', 'Category', 'Amount (PHP)', 'Description'];

        return $this->streamCsv('expense-report-' . now()->format('Y-m-d') . '.csv', $headers, $rows);
    }
}