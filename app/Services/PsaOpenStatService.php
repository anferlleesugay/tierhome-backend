<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Real, working integration with the Philippine Statistics Authority's
 * OpenSTAT API (PXWeb platform) — genuine public government data,
 * licensed CC BY 4.0 for free reuse with attribution.
 *
 * Docs:  https://openstat.psa.gov.ph/API-Documentation
 * Format: POST request, JSON query body, response format configurable
 *         (csv, json, xlsx, json-stat, json-stat2)
 * Rate limit: 10 requests per 10 seconds per connection (per PSA docs)
 *
 * IMPORTANT — what this can and cannot honestly provide:
 * PSA publishes aggregate national/regional statistics (average family
 * income, population, labor force data, etc.) — NOT platform-specific
 * transaction data. This service is used to fetch real context (e.g.,
 * "average Philippine family income") to display alongside TierHome's
 * own Affordability Ranking results, NOT to generate search/reservation
 * volume data, which no public dataset tracks.
 *
 * SETUP REQUIRED: the exact .px table path below is a placeholder.
 * To get the real one for a specific statistic (e.g., Family Income and
 * Expenditure Survey):
 *   1. Go to https://openstat.psa.gov.ph/
 *   2. Navigate: Database > Demographic and Social Statistics >
 *      Income and Consumption
 *   3. Select the specific table (e.g., "Average Family Income")
 *   4. Choose variables (region, year) and click Continue
 *   5. Open the "Connect to API" tab and copy the generated API URL
 *   6. Paste that URL into PSA_FAMILY_INCOME_TABLE_URL in your .env file
 */
class PsaOpenStatService
{
    private const BASE_URL = 'https://openstat.psa.gov.ph:443/PXWeb/api/v1/en';
    private const CACHE_TTL_HOURS = 24; // PSA data updates infrequently (quarterly/annually) — safe to cache

    /**
     * Fetch a statistic from a specific PSA OpenSTAT table.
     *
     * @param string $tableUrl  Full API URL for the table, generated via
     *                           PSA's "Connect to API" UI (see class docblock)
     * @param array  $query     PXWeb query filters, e.g.:
     *                           [['code' => 'Year', 'selection' => ['filter' => 'item', 'values' => ['2024']]]]
     * @return array|null       Parsed rows, or null on failure (never throws —
     *                           this is supplementary context data, not
     *                           something that should ever break a page load)
     */
    public function fetchStatistic(string $tableUrl, array $query = []): ?array
    {
        $cacheKey = 'psa_openstat_' . md5($tableUrl . json_encode($query));

        return Cache::remember($cacheKey, now()->addHours(self::CACHE_TTL_HOURS), function () use ($tableUrl, $query) {
            try {
                $response = Http::timeout(10)
                    ->withHeaders(['Content-Type' => 'application/json'])
                    ->post($tableUrl, [
                        'query' => $query,
                        'response' => ['format' => 'json-stat2'],
                    ]);

                if (!$response->successful()) {
                    Log::warning('PsaOpenStatService: request failed', [
                        'status' => $response->status(),
                        'url' => $tableUrl,
                    ]);
                    return null;
                }

                return $this->parseJsonStat2($response->json());

            } catch (\Exception $e) {
                Log::warning('PsaOpenStatService: fetch failed — ' . $e->getMessage());
                return null;
            }
        });
    }

    /**
     * Convenience method: fetch the average family income, expenditure,
     * and savings statistic for Angeles City, for display alongside
     * Affordability Ranking results.
     *
     * Table: PSA Family Income and Expenditure Survey (FIES) 2023,
     * Table 8 — Average Income, Average Expenditure, and Average Savings
     * of Families, by Region/Province/HUC and Per Capita Income Decile.
     * Scoped to: Region/Province/HUC = City of Angeles (code 44),
     * Per Capita Income Decile = All Income Groups (code 0).
     *
     * Requires PSA_FAMILY_INCOME_TABLE_URL to be set in .env — see class
     * docblock for how this was generated from PSA's own website.
     */
    public function getAverageFamilyIncome(): ?array
    {
        $tableUrl = config('services.psa.family_income_table_url');

        if (!$tableUrl) {
            Log::info('PsaOpenStatService: PSA_FAMILY_INCOME_TABLE_URL not configured, skipping.');
            return null;
        }

        return $this->fetchStatistic($tableUrl, [
            [
                'code' => 'Region, Province, and HUC',
                'selection' => ['filter' => 'item', 'values' => ['44']], // City of Angeles
            ],
            [
                'code' => 'Per Capita Income Decile',
                'selection' => ['filter' => 'item', 'values' => ['0']], // All Income Groups
            ],
        ]);
    }

    /**
     * PXWeb's json-stat2 format nests values inside a dimension structure
     * rather than flat rows. This flattens it into a simple label => value
     * array, which is far easier to use in a Blade/React view.
     */
    private function parseJsonStat2(?array $data): ?array
    {
        if (!$data || !isset($data['value']) || !isset($data['dimension'])) {
            return null;
        }

        $values = $data['value'];
        $labels = [];

        // Find the last dimension's category labels (usually the actual
        // data series, e.g., years or regions) to pair with each value
        foreach ($data['dimension'] as $dimKey => $dim) {
            if ($dimKey === 'id' || $dimKey === 'size' || $dimKey === 'role') continue;
            if (isset($dim['category']['label'])) {
                $labels = array_values($dim['category']['label']);
            }
        }

        $result = [];
        foreach ($values as $i => $value) {
            $result[] = [
                'label' => $labels[$i] ?? "Value {$i}",
                'value' => $value,
            ];
        }

        return [
            'title'  => $data['label'] ?? 'PSA Statistic',
            'source' => 'Philippine Statistics Authority (PSA) OpenSTAT — CC BY 4.0',
            'data'   => $result,
        ];
    }
}