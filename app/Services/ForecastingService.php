<?php

namespace App\Services;

/**
 * Extracted from AnalyticsController for isolated unit testability.
 * Pure calculation logic — no database access, no side effects — making
 * this ideal for genuine Unit Testing (as opposed to Feature/Integration
 * testing, which would require booting the framework and touching the DB).
 *
 * Contains the two core algorithms behind TierHome's forecasting feature:
 *   1. Ordinary least-squares linear regression (y = mx + b)
 *   2. Walk-forward cross-validation (time-series-appropriate validation,
 *      never trains on future months relative to what it predicts)
 */
class ForecastingService
{
    /**
     * Fits a simple linear regression (y = mx + b) to the given historical
     * values and projects forward the requested number of periods.
     *
     * @param array $values       Historical values, in chronological order
     * @param int   $periodsAhead How many future periods to forecast
     * @return array Forecasted values, one per period ahead
     */
    public function linearRegressionForecastMulti(array $values, int $periodsAhead = 1): array
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
     * Walk-forward cross-validation: trains only on months preceding each
     * prediction point (never future months), then evaluates against the
     * actual outcome for that month. Computes R², RMSE, and MAE across
     * every validation point produced.
     *
     * @param array $values        Historical values, in chronological order
     * @param int   $minTrainMonths Minimum training window before the first
     *                              validation attempt (default 3)
     * @return array{n:int, r2:?float, rmse:?float, mae:?float, pairs:array}
     */
    public function walkForwardValidate(array $values, int $minTrainMonths = 3): array
    {
        $n = count($values);
        $pairs = [];

        for ($split = $minTrainMonths; $split < $n; $split++) {
            $train     = array_slice($values, 0, $split);
            $actual    = $values[$split];
            $predicted = $this->linearRegressionForecastMulti($train, 1)[0];
            $pairs[]   = ['month_index' => $split, 'actual' => $actual, 'predicted' => $predicted];
        }

        $count = count($pairs);
        if ($count === 0) {
            return ['n' => 0, 'r2' => null, 'rmse' => null, 'mae' => null, 'pairs' => []];
        }

        $actuals    = array_column($pairs, 'actual');
        $predicteds = array_column($pairs, 'predicted');
        $meanActual = array_sum($actuals) / $count;

        $sumSqError  = 0;
        $sumAbsError = 0;
        $sumSqTotal  = 0;

        for ($i = 0; $i < $count; $i++) {
            $error = $actuals[$i] - $predicteds[$i];
            $sumSqError  += $error * $error;
            $sumAbsError += abs($error);
            $sumSqTotal  += ($actuals[$i] - $meanActual) ** 2;
        }

        $mae  = round($sumAbsError / $count, 2);
        $rmse = round(sqrt($sumSqError / $count), 2);
        $r2   = $sumSqTotal > 0 ? round(1 - ($sumSqError / $sumSqTotal), 3) : null;

        return ['n' => $count, 'r2' => $r2, 'rmse' => $rmse, 'mae' => $mae, 'pairs' => $pairs];
    }
}