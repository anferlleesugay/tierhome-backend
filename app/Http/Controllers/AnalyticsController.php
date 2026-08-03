<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\PropertyListing;
use App\Models\User;
use App\Models\IdentityVerification;
use App\Models\SearchLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class AnalyticsController extends Controller
{
    /**
     * ADMIN: Platform-wide analytics
     */
    public function adminOverview()
    {
        try {
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

            // Monthly reservation counts (last 6 months, including current)
            $reservationsByMonth = Reservation::where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);

            // ✅ Monthly search/demand activity — the real "tenants looking for a place" signal
            $searchesByMonth = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);

            // Most-searched barangays (top 5) — useful demand-location insight
            $topBarangays = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereNotNull('barangay')
                ->selectRaw('barangay, COUNT(*) as count')
                ->groupBy('barangay')
                ->orderByDesc('count')
                ->limit(5)
                ->get();

            // Monthly new user registrations
            $usersByMonth = User::where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $userMonthlyData = $this->fillMissingMonths($usersByMonth, $sixMonthsAgo);

            // Summary counts
            $totalUsers        = User::count();
            $totalTenants       = User::where('role', 'tenant')->count();
            $totalLandlords     = User::where('role', 'landlord')->count();
            $totalListings      = PropertyListing::count();
            $verifiedListings   = PropertyListing::where('is_verified', true)->count();
            $totalReservations  = Reservation::count();
            $pendingReservations = Reservation::where('status', 'pending')->count();
            $approvedReservations = Reservation::where('status', 'approved')->count();
            $totalSearches      = SearchLog::count();

            $verifiedUsers = IdentityVerification::where('status', 'approved')->count();
            $pendingVerifications = IdentityVerification::where('status', 'pending')->count();

            $statusBreakdown = Reservation::selectRaw('status, COUNT(*) as count')
                ->groupBy('status')
                ->get();

            // ✅ Multi-month forecast (3 months forward) based on search/demand activity
            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);

            $insight = $this->generateInsight(
                'platform-wide tenant demand (searches, filters, and affordability checks) and actual reservations',
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'summary' => [
                        'total_users'            => $totalUsers,
                        'total_tenants'           => $totalTenants,
                        'total_landlords'         => $totalLandlords,
                        'total_listings'          => $totalListings,
                        'verified_listings'       => $verifiedListings,
                        'total_reservations'      => $totalReservations,
                        'pending_reservations'    => $pendingReservations,
                        'approved_reservations'   => $approvedReservations,
                        'verified_users'          => $verifiedUsers,
                        'pending_verifications'   => $pendingVerifications,
                        'total_searches'          => $totalSearches,
                    ],
                    'reservations_by_month' => $monthlyData,
                    'searches_by_month'     => $searchMonthlyData,
                    'top_barangays'         => $topBarangays,
                    'users_by_month'        => $userMonthlyData,
                    'status_breakdown'      => $statusBreakdown,
                    'forecast' => [
                        'demand_next_3_months'      => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                     => $insight,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('AdminOverview analytics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * LANDLORD: Analytics for their own listings
     */
    public function landlordOverview()
    {
        try {
            $landlordId = auth()->id();
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

            $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');
            $landlordBarangays = PropertyListing::where('landlord_id', $landlordId)
                ->pluck('barangay')
                ->filter()
                ->map(fn($b) => trim(str_ireplace('barangay', '', $b)))
                ->unique();

            $reservationsByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);

            // ✅ Demand signal specific to this landlord's barangay(s) — how many tenants are searching in their area(s)
            $searchesByMonth = SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereIn(\DB::raw("TRIM(REPLACE(LOWER(barangay), 'barangay', ''))"), $landlordBarangays->map(fn($b) => strtolower(trim($b))))
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);

            $totalRevenue = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->sum('property_listings.reservation_fee');

            $revenueByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('payment_status', 'paid')
                ->where('reservations.created_at', '>=', $sixMonthsAgo)
                ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                ->selectRaw("DATE_FORMAT(reservations.created_at, '%Y-%m') as month, SUM(property_listings.reservation_fee) as total")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $revenueMonthlyData = $this->fillMissingMonths($revenueByMonth, $sixMonthsAgo, 'total');

            $listingPerformance = PropertyListing::where('landlord_id', $landlordId)
                ->withCount(['reservations as total_reservations'])
                ->withCount(['reservations as approved_reservations' => function ($q) {
                    $q->where('status', 'approved');
                }])
                ->get(['id', 'title', 'price', 'status', 'barangay']);

            $summary = [
                'total_listings'       => $listingIds->count(),
                'total_reservations'   => Reservation::whereIn('listing_id', $listingIds)->count(),
                'pending_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'pending')->count(),
                'approved_reservations' => Reservation::whereIn('listing_id', $listingIds)->where('status', 'approved')->count(),
                'total_revenue'        => (float) $totalRevenue,
                'area_searches'        => $searchMonthlyData->sum('count'),
            ];

            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);

            $insight = $this->generateInsight(
                'tenant search activity in your listing areas and actual reservation requests',
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'summary'                => $summary,
                    'reservations_by_month'  => $monthlyData,
                    'searches_by_month'      => $searchMonthlyData,
                    'revenue_by_month'       => $revenueMonthlyData,
                    'listing_performance'    => $listingPerformance,
                    'forecast' => [
                        'demand_next_3_months'       => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                      => $insight,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('LandlordOverview analytics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    // ================================================
    // PRIVATE HELPERS
    // ================================================

    private function fillMissingMonths($data, Carbon $startMonth, $valueKey = 'count')
    {
        $map = [];
        foreach ($data as $row) {
            $map[$row->month] = $row->{$valueKey} ?? 0;
        }

        $result = collect();
        $cursor = $startMonth->copy();
        for ($i = 0; $i < 6; $i++) {
            $key = $cursor->format('Y-m');
            $result->push([
                'month' => $cursor->format('M Y'),
                'count' => (float) ($map[$key] ?? 0),
            ]);
            $cursor->addMonth();
        }

        return $result;
    }

    /**
     * Linear Regression forecast — predicts the next single data point.
     * y = mx + b, ordinary least squares. Deterministic, fully explainable.
     */
    private function linearRegressionForecast(array $values): float
    {
        $forecasts = $this->linearRegressionForecastMulti($values, 1);
        return $forecasts[0] ?? 0;
    }

    /**
     * Linear Regression forecast — predicts the next N data points forward.
     *
     * Fits a single line (y = mx + b) to the historical data, then projects
     * it forward for `periodsAhead` additional time steps. This is the same
     * deterministic least-squares method as the single-month version, just
     * evaluated at multiple future x values instead of one.
     */
    private function linearRegressionForecastMulti(array $values, int $periodsAhead = 1): array
    {
        $n = count($values);
        if ($n < 2) {
            $flat = $n === 1 ? $values[0] : 0;
            return array_fill(0, $periodsAhead, $flat);
        }

        $x = range(0, $n - 1);
        $y = $values;

        $sumX  = array_sum($x);
        $sumY  = array_sum($y);
        $sumXY = 0;
        $sumX2 = 0;

        for ($i = 0; $i < $n; $i++) {
            $sumXY += $x[$i] * $y[$i];
            $sumX2 += $x[$i] * $x[$i];
        }

        $denominator = ($n * $sumX2) - ($sumX * $sumX);

        if ($denominator == 0) {
            $flat = round(array_sum($y) / $n, 1);
            return array_fill(0, $periodsAhead, $flat);
        }

        $m = (($n * $sumXY) - ($sumX * $sumY)) / $denominator;
        $b = ($sumY - ($m * $sumX)) / $n;

        $forecasts = [];
        for ($step = 0; $step < $periodsAhead; $step++) {
            $futureX = $n + $step;
            $predicted = ($m * $futureX) + $b;
            $forecasts[] = round(max(0, $predicted), 1);
        }

        return $forecasts;
    }

    /**
     * Generate a plain-language insight about the trend using Gemini.
     * Falls back to a rule-based sentence if the AI call fails.
     */
    private function generateInsight(string $context, array $demandValues, float $demandForecast, array $reservationValues = []): string
    {
        $apiKey = config('services.gemini.key');
        $model  = config('services.gemini.model', 'gemini-3.1-flash-lite');

        if (!$apiKey) {
            return $this->fallbackInsight($demandValues, $demandForecast);
        }

        try {
            $prompt = "You are analyzing {$context} for a housing platform called TierHome. "
                . "Monthly tenant search/interest activity for the last 6 months: [" . implode(', ', $demandValues) . "]. "
                . "Monthly actual reservations for the last 6 months: [" . implode(', ', $reservationValues) . "]. "
                . "The forecast for next month's search/interest activity is: {$demandForecast}. "
                . "Write exactly 2 short sentences summarizing the trend in plain, friendly language "
                . "suitable for a dashboard. Focus on whether tenant interest is growing, and whether that interest "
                . "is converting into actual reservations. Do not use technical jargon. Do not repeat the raw numbers verbatim.";

            $res = Http::timeout(8)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [
                        ['role' => 'user', 'parts' => [['text' => $prompt]]],
                    ],
                    'generationConfig' => [
                        'temperature'     => 0.4,
                        'maxOutputTokens' => 150,
                    ],
                ]
            );

            if ($res->successful()) {
                $text = data_get($res->json(), 'candidates.0.content.parts.0.text');
                if ($text) return trim($text);
            }
        } catch (\Exception $e) {
            Log::warning('Gemini insight generation failed: ' . $e->getMessage());
        }

        return $this->fallbackInsight($demandValues, $demandForecast);
    }

    private function fallbackInsight(array $historicalValues, float $forecast): string
    {
        $recent = end($historicalValues) ?: 0;
        if ($forecast > $recent) {
            return "Tenant interest appears to be trending upward, with next month's search activity projected to be higher than the current level.";
        } elseif ($forecast < $recent) {
            return "Tenant interest appears to be trending downward, with next month's search activity projected to be lower than the current level.";
        }
        return "Tenant interest appears relatively stable, with next month's search activity projected to be similar to the current level.";
    }
}