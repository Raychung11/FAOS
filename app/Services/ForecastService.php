<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Demand forecasting. Built-in method = weighted moving average with a
 * day-of-week seasonality factor (works fully offline). When an AI provider
 * is configured, AiClient adds a narrative recommendation on top.
 */
final class ForecastService
{
    /** Forecast daily demand per product for an outlet over N days. */
    public static function forecast(int $company, ?int $outletId, int $horizonDays = 14, int $lookbackDays = 56): array
    {
        $where = "st.company_id = ? AND st.status <> 'voided'
                  AND st.sold_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)";
        $args = [$company, $lookbackDays];
        if ($outletId !== null) {
            $where .= ' AND st.outlet_id = ?';
            $args[] = $outletId;
        }

        $rows = Database::all(
            "SELECT si.product_id, DATE(st.sold_at) d, DAYOFWEEK(st.sold_at) dow,
                    SUM(si.qty) qty
             FROM sales_transactions st
             JOIN sales_items si ON si.transaction_id = st.id
             WHERE $where
             GROUP BY si.product_id, d, dow",
            $args
        );

        // Aggregate per product.
        $byProduct = [];
        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $byProduct[$pid]['daily'][] = (float) $r['qty'];
            $byProduct[$pid]['dow'][(int) $r['dow']][] = (float) $r['qty'];
        }

        $out = [];
        foreach ($byProduct as $pid => $data) {
            $daily = $data['daily'];
            $avg = array_sum($daily) / max(1, count($daily));
            $std = self::stddev($daily, $avg);

            $product = Database::first('SELECT sku, name FROM products WHERE id = ?', [$pid]);
            $series = [];
            for ($i = 1; $i <= $horizonDays; $i++) {
                $date = date('Y-m-d', strtotime("+$i day"));
                $dow = (int) date('w', strtotime($date)) + 1; // MySQL DAYOFWEEK 1..7
                $seasonal = isset($data['dow'][$dow]) && $data['dow'][$dow]
                    ? array_sum($data['dow'][$dow]) / count($data['dow'][$dow])
                    : $avg;
                // Blend overall avg with day-of-week pattern.
                $pred = round(0.4 * $avg + 0.6 * $seasonal, 2);
                $series[] = [
                    'date'        => $date,
                    'predicted'   => $pred,
                    'lower'       => round(max(0, $pred - 1.28 * $std), 2),
                    'upper'       => round($pred + 1.28 * $std, 2),
                ];
            }

            $out[] = [
                'product_id' => $pid,
                'sku'        => $product['sku'] ?? '',
                'name'       => $product['name'] ?? '',
                'avg_daily'  => round($avg, 2),
                'forecast'   => $series,
                'next7_total' => round(array_sum(array_column(array_slice($series, 0, 7), 'predicted')), 2),
            ];
        }

        usort($out, fn ($a, $b) => $b['next7_total'] <=> $a['next7_total']);
        return $out;
    }

    /** Persist a forecast snapshot. */
    public static function persist(int $company, ?int $outletId, array $forecast): int
    {
        $n = 0;
        foreach ($forecast as $f) {
            foreach ($f['forecast'] as $pt) {
                Database::insert(
                    'INSERT INTO sales_forecasts
                      (company_id, outlet_id, product_id, forecast_date,
                       predicted_qty, lower_bound, upper_bound, method)
                     VALUES (?,?,?,?,?,?,?,?)',
                    [$company, $outletId, $f['product_id'], $pt['date'],
                     $pt['predicted'], $pt['lower'], $pt['upper'], 'moving_avg']
                );
                $n++;
            }
        }
        return $n;
    }

    /** Purchase recommendation = forecasted 7-day demand - on-hand. */
    public static function purchaseRecommendation(int $company, ?int $outletId = null): array
    {
        $fc = self::forecast($company, $outletId, 7);
        $recs = [];
        foreach ($fc as $f) {
            $onHand = (float) Database::scalar(
                'SELECT COALESCE(SUM(qty),0) FROM stock_balances WHERE product_id = ?',
                [$f['product_id']]
            );
            $need = round($f['next7_total'] - $onHand, 2);
            if ($need > 0) {
                $recs[] = [
                    'product_id'   => $f['product_id'],
                    'sku'          => $f['sku'],
                    'name'         => $f['name'],
                    'on_hand'      => $onHand,
                    'forecast_7d'  => $f['next7_total'],
                    'recommend_qty' => $need,
                ];
            }
        }
        return $recs;
    }

    /** Statistical anomaly detection on recent daily sales (z-score). */
    public static function anomalies(int $company, ?int $outletId = null, float $z = 2.5): array
    {
        $fc = self::forecast($company, $outletId, 1, 56);
        $where = "st.company_id = ? AND st.status <> 'voided'
                  AND st.sold_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)";
        $args = [$company];
        if ($outletId !== null) {
            $where .= ' AND st.outlet_id = ?';
            $args[] = $outletId;
        }
        $recent = Database::all(
            "SELECT si.product_id, DATE(st.sold_at) d, SUM(si.qty) qty
             FROM sales_transactions st JOIN sales_items si ON si.transaction_id = st.id
             WHERE $where GROUP BY si.product_id, d",
            $args
        );
        $avgMap = [];
        foreach ($fc as $f) {
            $avgMap[$f['product_id']] = $f['avg_daily'];
        }
        $anom = [];
        foreach ($recent as $r) {
            $pid = (int) $r['product_id'];
            $avg = $avgMap[$pid] ?? 0;
            if ($avg > 0 && abs((float) $r['qty'] - $avg) > $z * max(1, $avg)) {
                $anom[] = [
                    'product_id' => $pid,
                    'date'       => $r['d'],
                    'qty'        => (float) $r['qty'],
                    'expected'   => $avg,
                    'direction'  => $r['qty'] > $avg ? 'spike' : 'drop',
                ];
            }
        }
        return $anom;
    }

    private static function stddev(array $values, float $mean): float
    {
        $n = count($values);
        if ($n < 2) {
            return 0.0;
        }
        $sum = 0.0;
        foreach ($values as $v) {
            $sum += ($v - $mean) ** 2;
        }
        return sqrt($sum / ($n - 1));
    }
}
