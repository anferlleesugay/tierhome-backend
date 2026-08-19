<?php

namespace App\Http\Controllers;

use App\Models\Reservation;
use App\Models\PropertyListing;
use App\Models\User;
use App\Models\IdentityVerification;
use App\Models\SearchLog;
use App\Models\Message;
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
                ->with('images')
                ->get(['id', 'title', 'price', 'status', 'barangay', 'is_verified']);

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

    /**
     * LANDLORD: Dashboard widgets — key stats, pending-task tracker, and a combined
     * activity feed (reservations + messages). Built directly from the property owner
     * interview findings: both respondents independently asked for these three things.
     */
    public function landlordDashboardWidgets()
    {
        try {
            $landlordId = auth()->id();
            $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');

            $startOfMonth  = Carbon::now()->startOfMonth();
            $checkInWindow = Carbon::now()->addDays(14)->toDateString();
            $today         = Carbon::now()->toDateString();

            // ── KEY STATS ──
            $stats = [
                'active_listings' => PropertyListing::where('landlord_id', $landlordId)
                    ->where('status', 'available')->count(),
                'pending_reservations' => Reservation::whereIn('listing_id', $listingIds)
                    ->where('status', 'pending')->count(),
                'revenue_this_month' => (float) Reservation::whereIn('reservations.listing_id', $listingIds)
                    ->where('payment_status', 'paid')
                    ->where('reservations.paid_at', '>=', $startOfMonth)
                    ->join('property_listings', 'reservations.listing_id', '=', 'property_listings.id')
                    ->sum('property_listings.reservation_fee'),
                'upcoming_checkins' => Reservation::whereIn('listing_id', $listingIds)
                    ->where('status', 'approved')
                    ->whereBetween('move_in_date', [$today, $checkInWindow])
                    ->count(),
            ];

            // ── TASK TRACKER ── (both interview respondents asked for this specifically)
            $tasks = [
                'pending_reservations' => $stats['pending_reservations'],
                'pending_listing_approvals' => PropertyListing::where('landlord_id', $landlordId)
                    ->where('is_verified', false)
                    ->where('status', '!=', 'rejected')
                    ->count(),
                'unread_messages' => Message::whereHas('conversation', fn($q) => $q->where('landlord_id', $landlordId))
                    ->where('sender_id', '!=', $landlordId)
                    ->whereNull('read_at')
                    ->count(),
            ];

            // ── ACTIVITY FEED ── (last 10 combined events — reservations + messages)
            $recentReservations = Reservation::whereIn('listing_id', $listingIds)
                ->with(['tenant:id,first_name,last_name', 'listing:id,title'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->filter(fn($r) => $r->tenant && $r->listing)
                ->map(fn($r) => [
                    'type'      => 'reservation',
                    'message'   => "{$r->tenant->first_name} {$r->tenant->last_name} requested to reserve \"{$r->listing->title}\"",
                    'status'    => $r->status,
                    'timestamp' => $r->created_at,
                ]);

            $recentMessages = Message::whereHas('conversation', fn($q) => $q->where('landlord_id', $landlordId))
                ->where('sender_id', '!=', $landlordId)
                ->with(['sender:id,first_name,last_name', 'conversation:id,listing_id', 'conversation.listing:id,title'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
                ->filter(fn($m) => $m->sender)
                ->map(fn($m) => [
                    'type'      => 'message',
                    'message'   => "{$m->sender->first_name} {$m->sender->last_name} sent a message"
                        . ($m->conversation && $m->conversation->listing ? " about \"{$m->conversation->listing->title}\"" : ""),
                    'status'    => null,
                    'timestamp' => $m->created_at,
                ]);

            $activityFeed = $recentReservations->concat($recentMessages)
                ->sortByDesc('timestamp')
                ->take(10)
                ->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'stats'         => $stats,
                    'tasks'         => $tasks,
                    'activity_feed' => $activityFeed,
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('landlordDashboardWidgets error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    public function landlordOverviewForAdmin($landlordId)
    {
        try {
            $landlord = User::where('id', $landlordId)->where('role', 'landlord')->firstOrFail();
 
            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();
 
            $listingIds = PropertyListing::where('landlord_id', $landlordId)->pluck('id');
            $landlordBarangays = PropertyListing::where('landlord_id', $landlordId)->pluck('barangay')->filter()->unique();
 
            $reservationsByMonth = Reservation::whereIn('listing_id', $listingIds)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);
 
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
                ->with('images')
                ->get(['id', 'title', 'price', 'status', 'barangay', 'is_verified']);
 
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
                "tenant search activity and reservation requests for {$landlord->first_name} {$landlord->last_name}'s listings",
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );
 
            return response()->json([
                'success' => true,
                'data' => [
                    'landlord'               => $landlord->only(['id', 'first_name', 'last_name', 'email', 'profile_picture']),
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
            Log::error('landlordOverviewForAdmin error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }
 
    /**
     * Per-listing analytics — accessible by admin (any listing) or the owning landlord (their own only).
     * Note: tenant search demand is only ever logged at the barangay level (see SearchLog), never
     * per-listing, so "area context" here reflects the listing's barangay, not the listing itself.
     */
    public function listingAnalytics($id)
    {
        try {
            $listing = PropertyListing::with('images')->findOrFail($id);

            $user = auth()->user();
            if ($user->role !== 'admin' && (int) $listing->landlord_id !== (int) $user->id) {
                return response()->json(['success' => false, 'message' => 'You are not authorized to view this listing\'s analytics.'], 403);
            }

            $sixMonthsAgo = Carbon::now()->subMonths(5)->startOfMonth();

            $reservationsByMonth = Reservation::where('listing_id', $id)
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $monthlyData = $this->fillMissingMonths($reservationsByMonth, $sixMonthsAgo);

            // Area-level context only — searches are logged per barangay, not per listing
            $barangayNormalized = strtolower(trim(str_ireplace('barangay', '', $listing->barangay ?? '')));
            $searchesByMonth = $barangayNormalized !== '' ? SearchLog::where('created_at', '>=', $sixMonthsAgo)
                ->whereRaw("TRIM(REPLACE(LOWER(barangay), 'barangay', '')) = ?", [$barangayNormalized])
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get() : collect();
            $searchMonthlyData = $this->fillMissingMonths($searchesByMonth, $sixMonthsAgo);

            $fee = (float) ($listing->reservation_fee ?? 0);
            $paidByMonth = Reservation::where('listing_id', $id)
                ->where('payment_status', 'paid')
                ->where('created_at', '>=', $sixMonthsAgo)
                ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count")
                ->groupBy('month')
                ->orderBy('month')
                ->get();
            $revenueMonthlyData = $this->fillMissingMonths($paidByMonth, $sixMonthsAgo)
                ->map(fn($row) => ['month' => $row['month'], 'count' => $row['count'] * $fee]);

            $savedCount = \App\Models\SavedListing::where('listing_id', $id)->count();

            $summary = [
                'total_reservations'    => Reservation::where('listing_id', $id)->count(),
                'pending_reservations'  => Reservation::where('listing_id', $id)->where('status', 'pending')->count(),
                'approved_reservations' => Reservation::where('listing_id', $id)->where('status', 'approved')->count(),
                'rejected_reservations' => Reservation::where('listing_id', $id)->where('status', 'rejected')->count(),
                'total_revenue'         => (float) (Reservation::where('listing_id', $id)->where('payment_status', 'paid')->count() * $fee),
                'saved_count'           => $savedCount,
                'area_searches'         => $searchMonthlyData->sum('count'),
            ];

            $demandForecast = $this->linearRegressionForecastMulti($searchMonthlyData->pluck('count')->toArray(), 3);
            $reservationForecast = $this->linearRegressionForecastMulti($monthlyData->pluck('count')->toArray(), 3);

            $insight = $this->generateInsight(
                "tenant interest and reservation activity specifically for the listing \"{$listing->title}\"",
                $searchMonthlyData->pluck('count')->toArray(),
                $demandForecast[0] ?? 0,
                $monthlyData->pluck('count')->toArray()
            );

            return response()->json([
                'success' => true,
                'data' => [
                    'listing'               => $listing->only(['id', 'title', 'price', 'status', 'barangay', 'is_verified', 'property_type', 'reservation_fee']),
                    'images'                => $listing->images,
                    'summary'               => $summary,
                    'reservations_by_month' => $monthlyData,
                    'searches_by_month'     => $searchMonthlyData,
                    'revenue_by_month'      => $revenueMonthlyData,
                    'forecast' => [
                        'demand_next_3_months'       => $demandForecast,
                        'reservations_next_3_months' => $reservationForecast,
                        'insight'                     => $insight,
                    ],
                ],
            ]);

        } catch (\Exception $e) {
            Log::error('listingAnalytics error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: every listing platform-wide (regardless of owner), with quick stats each.
     */
    public function allListingsSummary()
    {
        try {
            $listings = PropertyListing::with(['landlord:id,first_name,last_name,email', 'images'])
                ->withCount(['reservations as total_reservations'])
                ->withCount(['reservations as approved_reservations' => function ($q) {
                    $q->where('status', 'approved');
                }])
                ->withCount(['reservations as pending_reservations' => function ($q) {
                    $q->where('status', 'pending');
                }])
                ->get(['id', 'title', 'landlord_id', 'price', 'status', 'barangay', 'is_verified', 'reservation_fee']);

            $paidCounts = Reservation::where('payment_status', 'paid')
                ->selectRaw('listing_id, COUNT(*) as paid_count')
                ->groupBy('listing_id')
                ->pluck('paid_count', 'listing_id');

            $savedCounts = \App\Models\SavedListing::selectRaw('listing_id, COUNT(*) as cnt')
                ->groupBy('listing_id')
                ->pluck('cnt', 'listing_id');

            $listings->each(function ($listing) use ($paidCounts, $savedCounts) {
                $paid = $paidCounts[$listing->id] ?? 0;
                $listing->total_revenue = (float) ($paid * ($listing->reservation_fee ?? 0));
                $listing->saved_count   = $savedCounts[$listing->id] ?? 0;
            });

            $summary = [
                'total_listings'      => $listings->count(),
                'verified_listings'   => $listings->where('is_verified', true)->count(),
                'total_reservations'  => (int) $listings->sum('total_reservations'),
                'total_revenue'       => (float) $listings->sum('total_revenue'),
            ];

            return response()->json(['success' => true, 'data' => ['listings' => $listings, 'summary' => $summary]]);

        } catch (\Exception $e) {
            Log::error('allListingsSummary error: ' . $e->getMessage());
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * ADMIN: list all landlords with basic stats, for the "click into a landlord" list view
     */
    public function landlordsList()
    {
        try {
            $landlords = User::where('role', 'landlord')
                ->get(['id', 'first_name', 'last_name', 'email', 'profile_picture', 'is_active']);
 
            // Attach listing count per landlord without assuming a relationship exists
            $counts = PropertyListing::selectRaw('landlord_id, COUNT(*) as cnt')
                ->groupBy('landlord_id')
                ->pluck('cnt', 'landlord_id');
 
            $landlords->each(function ($l) use ($counts) {
                $l->listings_count = $counts[$l->id] ?? 0;
            });
 
            return response()->json(['success' => true, 'data' => $landlords]);
        } catch (\Exception $e) {
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

            $res = Http::timeout(15)->post(
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