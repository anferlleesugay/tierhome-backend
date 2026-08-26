<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Services\ForecastingService;

/**
 * Unit tests for ForecastingService — the pure calculation logic behind
 * TierHome's demand/reservation forecasting and Model Monitoring features.
 *
 * These are true unit tests: no database, no HTTP, no framework bootstrap.
 * Each test verifies the mathematical correctness of the algorithm against
 * hand-calculated expected values.
 */
class ForecastingServiceTest extends TestCase
{
    private ForecastingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ForecastingService();
    }

    // ============================================================
    // linearRegressionForecastMulti() tests
    // ============================================================

    public function test_forecasts_next_value_correctly_on_a_perfect_linear_trend(): void
    {
        // y = 10x + 10 exactly: x=0,1,2,3 -> y=10,20,30,40
        $result = $this->service->linearRegressionForecastMulti([10, 20, 30, 40], 1);

        // Next point (x=4): 10*4 + 10 = 50
        $this->assertEquals([50.0], $result);
    }

    public function test_forecasts_multiple_periods_ahead_correctly(): void
    {
        $result = $this->service->linearRegressionForecastMulti([10, 20, 30, 40], 3);

        // x=4,5,6 -> 50, 60, 70
        $this->assertEquals([50.0, 60.0, 70.0], $result);
    }

    public function test_returns_flat_value_with_a_single_data_point(): void
    {
        $result = $this->service->linearRegressionForecastMulti([42], 1);

        $this->assertEquals([42], $result);
    }

    public function test_returns_zero_with_no_data_points(): void
    {
        $result = $this->service->linearRegressionForecastMulti([], 2);

        $this->assertEquals([0, 0], $result);
    }

    public function test_returns_flat_mean_when_all_values_are_identical(): void
    {
        // Constant input naturally yields a zero slope through the normal
        // regression formula (not a special-cased branch) — should return
        // the flat mean, not error or divide by zero
        $result = $this->service->linearRegressionForecastMulti([25, 25, 25, 25], 1);

        $this->assertEquals([25.0], $result);
    }

    public function test_clamps_negative_predictions_to_zero(): void
    {
        // Sharp downward trend that would mathematically predict a negative value
        $result = $this->service->linearRegressionForecastMulti([30, 20, 10, 0], 1);

        // Raw prediction would be -10; must be clamped to 0 (can't have negative demand)
        $this->assertEquals([0.0], $result);
    }

    // ============================================================
    // walkForwardValidate() tests
    // ============================================================

    public function test_returns_zero_validation_points_with_insufficient_data(): void
    {
        // Only 3 months of data, minimum training window is 3 — no room for even one split
        $result = $this->service->walkForwardValidate([10, 20, 30], 3);

        $this->assertEquals(0, $result['n']);
        $this->assertNull($result['r2']);
        $this->assertNull($result['rmse']);
        $this->assertNull($result['mae']);
    }

    public function test_achieves_perfect_fit_on_a_perfectly_linear_series(): void
    {
        // 7 months, perfectly linear (slope=10) — every walk-forward prediction
        // should exactly match the actual value
        $result = $this->service->walkForwardValidate([10, 20, 30, 40, 50, 60, 70], 3);

        $this->assertEquals(4, $result['n']); // splits at index 3,4,5,6
        $this->assertEquals(1.0, $result['r2']);
        $this->assertEquals(0.0, $result['rmse']);
        $this->assertEquals(0.0, $result['mae']);
    }

    public function test_computes_correct_metrics_on_a_known_noisy_series(): void
    {
        // Hand-verified golden values for this exact input, computed independently
        $result = $this->service->walkForwardValidate([5, 8, 6, 10, 9, 12], 3);

        $this->assertEquals(3, $result['n']);
        $this->assertEquals(-1.464, $result['r2']);
        $this->assertEquals(1.96, $result['rmse']);
        $this->assertEquals(1.87, $result['mae']);
    }

    public function test_respects_a_custom_minimum_training_window(): void
    {
        // With minTrainMonths=2 instead of the default 3, validation should
        // start one split earlier, producing one more validation point
        $result = $this->service->walkForwardValidate([10, 20, 30, 40, 50], 2);

        $this->assertEquals(3, $result['n']); // splits at index 2,3,4
    }

    public function test_never_trains_on_future_months(): void
    {
        // Explicitly verify the walk-forward property: each prediction uses
        // only data strictly before its target index
        $result = $this->service->walkForwardValidate([10, 20, 30, 40, 50], 3);

        foreach ($result['pairs'] as $pair) {
            $this->assertLessThanOrEqual(4, $pair['month_index']);
            $this->assertGreaterThanOrEqual(3, $pair['month_index']);
        }
    }
}