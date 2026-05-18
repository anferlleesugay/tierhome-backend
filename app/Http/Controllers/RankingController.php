<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\PropertyListing;
use App\Models\AffordabilityRanking;

class RankingController extends Controller
{
    public function store(Request $request)
    {
        // ================================
        // 1. VALIDATION
        // ================================
        $request->validate([
            'gross_income' => 'required|numeric|min:0',
            'monthly_expenses' => 'required|numeric|min:0',
            'utility_costs' => 'required|numeric|min:0',
            'available_savings' => 'required|numeric|min:0',
        ]);

        $user = auth()->user();

        // 🔒 ONLY TENANTS
        if ($user->role !== 'tenant') {
            return response()->json([
                'error' => 'Only tenants can use ranking system'
            ], 403);
        }

        // ================================
        // 2. FORMULA 1: DISPOSABLE INCOME
        // ================================
        $disposableIncome =
            $request->gross_income
            - $request->monthly_expenses
            - $request->utility_costs;

        $disposableIncome = max($disposableIncome, 0);

        // ================================
        // 3. FORMULA 2: MAX RENT (30%)
        // ================================
        $recommendedMaxRent = 0.30 * $disposableIncome;

        // ================================
        // 4. FETCH VERIFIED LISTINGS
        // ================================
        $listings = PropertyListing::where('is_verified', 1)
            ->where('status', 'available')
            ->get();

        $results = [];

        // ================================
        // 5–8. EVALUATE LISTINGS
        // ================================
        foreach ($listings as $listing) {

            $rent = (float) $listing->price;
            $moveInCost = $rent * 3;

            // Avoid division by zero
            $rentRatio = $disposableIncome > 0
                ? ($rent / $disposableIncome)
                : 1;

            $moveInRatio = $request->available_savings > 0
                ? ($moveInCost / $request->available_savings)
                : 1;

            // Composite Affordability Score (higher = more affordable, max 1.0)
            $rentScore    = max(0.0, 1.0 - $rentRatio);
            $moveInScore  = max(0.0, 1.0 - $moveInRatio);
            $compositeScore = round(($rentScore * 0.6) + ($moveInScore * 0.4), 4);

            // ================================
            // STRICT MANUSCRIPT LOGIC
            // ================================

            // HIGHLY AFFORDABLE
            if (
                $rent <= $recommendedMaxRent &&
                $request->available_savings >= $moveInCost
            ) {
                $tier = 'Highly Affordable';
                $priority = 1;
            }

            // MODERATELY AFFORDABLE
            elseif (
                (
                    $rent > $recommendedMaxRent &&
                    $rent <= ($disposableIncome * 0.40)
                )
                ||
                (
                    $request->available_savings >= ($moveInCost * 0.7) &&
                    $request->available_savings < $moveInCost
                )
            ) {
                $tier = 'Moderately Affordable';
                $priority = 2;
            }

            // FINANCIALLY RISKY
            else {
                $tier = 'Financially Risky';
                $priority = 3;
            }

            $results[] = [
                'listing'         => $listing->toArray(),
                'rent'            => $rent,
                'move_in_cost'    => $moveInCost,
                'tier'            => $tier,
                'priority'        => $priority,
                'composite_score' => (float) $compositeScore,
            ];
        }

        // ================================
        // 9. SORT RESULTS
        // Sort by tier priority first, then by composite score (higher = better) within tier
        // ================================
        usort($results, function ($a, $b) {
            if ($a['priority'] === $b['priority']) {
                // Within same tier: higher composite score = more affordable = ranked first
                return $b['composite_score'] <=> $a['composite_score'];
            }
            return $a['priority'] <=> $b['priority'];
        });

        // remove priority before returning, keep composite_score for frontend display
        $results = array_map(function ($item) {
            unset($item['priority']);
            return $item;
        }, $results);

        // ================================
        // 10. STORE RESULT
        // ================================
        AffordabilityRanking::create([
            'tenant_id' => $user->id,
            'gross_income' => $request->gross_income,
            'monthly_expenses' => $request->monthly_expenses,
            'utility_costs' => $request->utility_costs,
            'available_savings' => $request->available_savings,
            'disposable_income' => $disposableIncome,
            'recommended_max_rent' => $recommendedMaxRent,
            'results' => json_encode($results)
        ]);

        // ================================
        // RESPONSE
        // ================================
        return response()->json([
            'success' => true,
            'inputs' => [
                'gross_income' => $request->gross_income,
                'monthly_expenses' => $request->monthly_expenses,
                'utility_costs' => $request->utility_costs,
                'available_savings' => $request->available_savings
            ],
            'computed' => [
                'disposable_income' => $disposableIncome,
                'recommended_max_rent' => $recommendedMaxRent
            ],
            'ranked_listings' => $results
        ]);
    }

    // ================================
    // GET LAST RESULT (FIXED)
    // ================================
    public function myRanking()
    {
        try {
            $user = auth()->user();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }

            $ranking = AffordabilityRanking::where('tenant_id', $user->id)
                ->latest()
                ->first();

            if (!$ranking) {
                return response()->json([
                    'success' => false,
                    'message' => 'No ranking found. Please compute your affordability ranking first.',
                    'data' => null
                ], 404);
            }

            return response()->json([
                'success' => true,
                'data' => [
                    'id' => $ranking->id,
                    'tenant_id' => $ranking->tenant_id,
                    'gross_income' => $ranking->gross_income,
                    'monthly_expenses' => $ranking->monthly_expenses,
                    'utility_costs' => $ranking->utility_costs,
                    'available_savings' => $ranking->available_savings,
                    'disposable_income' => $ranking->disposable_income,
                    'recommended_max_rent' => $ranking->recommended_max_rent,
                    'results' => json_decode($ranking->results, true),
                    'created_at' => $ranking->created_at,
                    'updated_at' => $ranking->updated_at,
                ]
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}