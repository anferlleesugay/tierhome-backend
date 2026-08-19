<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\PropertyListing;
use App\Models\Reservation;
use Illuminate\Http\Request;
use Carbon\Carbon;

class ExpenseController extends Controller
{
    private function ownsListing($listingId): bool
    {
        return PropertyListing::where('id', $listingId)
            ->where('landlord_id', auth()->id())
            ->exists();
    }

    public function index(Request $request)
    {
        $landlordId = auth()->id();
        $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

        $query = Expense::whereIn('listing_id', $listingIds)->with('listing:id,title');

        if ($request->filled('listing_id')) {
            $query->where('listing_id', $request->query('listing_id'));
        }

        $expenses = $query->orderBy('expense_date', 'desc')->get();

        return response()->json(['success' => true, 'data' => $expenses]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'listing_id'   => 'required|exists:property_listings,id',
            'category'     => 'required|string|max:50',
            'amount'       => 'required|numeric|min:0',
            'description'  => 'nullable|string|max:500',
            'expense_date' => 'required|date',
        ]);

        if (!$this->ownsListing($validated['listing_id'])) {
            return response()->json(['success' => false, 'message' => 'Not authorized for this listing.'], 403);
        }

        $expense = Expense::create($validated);

        return response()->json(['success' => true, 'data' => $expense]);
    }

    public function destroy($id)
    {
        $expense = Expense::findOrFail($id);

        if (!$this->ownsListing($expense->listing_id)) {
            return response()->json(['success' => false, 'message' => 'Not authorized to delete this expense.'], 403);
        }

        $expense->delete();

        return response()->json(['success' => true]);
    }

    /**
     * Net income summary — directly answers Client 2's Q12: "understanding actual
     * profitability... Expenses, Net income."
     */
    public function summary(Request $request)
    {
        $landlordId = auth()->id();
        $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

        $range = $request->query('range', 'this_year');
        [$start, $end] = match ($range) {
            'this_month' => [Carbon::now()->startOfMonth(), Carbon::now()->endOfMonth()],
            default      => [Carbon::now()->startOfYear(), Carbon::now()->endOfYear()],
        };

        $totalExpenses = Expense::whereIn('listing_id', $listingIds)
            ->whereBetween('expense_date', [$start, $end])
            ->sum('amount');

        $totalRevenue = Reservation::whereIn('reservations.listing_id', $listingIds)
            ->where('payment_status', 'paid')
            ->whereBetween('reservations.paid_at', [$start, $end])
            ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
            ->sum('property_listings.reservation_fee');

        return response()->json([
            'success' => true,
            'data' => [
                'total_revenue'  => (float) $totalRevenue,
                'total_expenses' => (float) $totalExpenses,
                'net_income'     => (float) $totalRevenue - (float) $totalExpenses,
            ],
        ]);
    }
}