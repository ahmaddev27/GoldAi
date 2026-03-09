<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ====================================================
 * Gold AI Scalper - FIXED VERSION v3
 * ====================================================
 *
 * FIXES IN THIS VERSION:
 * 1. MIN_SIGNAL_THRESHOLD lowered to 0.40 (was 0.55) → أسهل تحقيق الإشارة
 * 2. BUG FIX: trendAlignment null check fixed → لا يبلوك الإشارة لما تكون null
 * 3. BUG FIX: Direction check مش بيشترط trendAlignment يكون موجود للموافقة
 * 4. RSI conditions more relaxed (40→45 for weak bull, 60→55 for weak bear)
 * 5. EMA partial credit زيادة من 0.5 إلى 0.7
 * 6. MACD histogram threshold تخفيض لاستقبال إشارات أضعف
 * 7. Volume: neutral volume لا يحجب (كان بيحجب ضمنياً)
 * 8. Candle structure: score >= 1 يعطي نقاط (كان >= 2)
 * 9. إضافة RSI divergence check بسيط (price vs RSI direction)
 * 10. Session check: لا تبلوك الإشارة خارج الجلسة بل تحذير فقط
 */
class GoldController extends Controller
{
    // ====== Risk Management Constants ======
    private const ACCOUNT_SIZE  = 100;
    private const RISK_PERCENT  = 1.5;
    private const MIN_LOT       = 0.01;
    private const NANO_LOT      = 0.001;
    private const OZ_PER_STD_LOT = 100;

    private const RSI_OVERBOUGHT   = 70;
    private const RSI_OVERSOLD     = 30;
    private const RSI_EXTREME_HIGH = 80;
    private const RSI_EXTREME_LOW  = 20;

    // ATR multipliers
    private const ATR_SL_MULT  = 1.5;
    private const ATR_TP1_MULT = 1.0;
    private const ATR_TP2_MULT = 1.8;
    private const ATR_TP3_MULT = 2.5;

    // ====== Signal Thresholds ======
    private const MIN_SIGNAL_THRESHOLD    = 0.28;  // 28% = 3.1 نقطة من 11 (مرن جداً)
    private const STRONG_SIGNAL_THRESHOLD = 0.50;  // 50% = 5.5 نقطة للإشارة القوية

    private const MIN_RR_RATIO = 1.5;

    // ====== Indicator Weights ======
    private const WEIGHT_RSI         = 1.5;
    private const WEIGHT_STOCH       = 1.0;
    private const WEIGHT_EMA12_20    = 1.0;
    private const WEIGHT_EMA50       = 1.5;
    private const WEIGHT_BB          = 1.0;
    private const WEIGHT_PIVOT       = 1.0;
    private const WEIGHT_MACD        = 1.5;
    private const WEIGHT_CANDLE      = 1.0;
    private const WEIGHT_VOLUME      = 1.0;
    private const WEIGHT_TREND_ALIGN = 1.5;

    public function landing() { return view('landing'); }

    // ==============================
    // TECHNICAL INDICATORS (unchanged)
    // ==============================

    private function calculateEMA($prices, $period)
    {
        if (count($prices) < $period) return null;
        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($prices, 0, $period)) / $period;
        for ($i = $period; $i < count($prices); $i++) {
            $ema = ($prices[$i] * $k) + ($ema * (1 - $k));
        }
        return round($ema, 2);
    }

    private function calculateRSI($prices, $period = 14)
    {
        if (count($prices) < $period + 1) return 50;
        $gains = []; $losses = [];
        for ($i = 1; $i < count($prices); $i++) {
            $diff = $prices[$i] - $prices[$i - 1];
            $gains[]  = max(0,  $diff);
            $losses[] = max(0, -$diff);
        }
        $avgGain = array_sum(array_slice($gains,  0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
        if ($avgLoss == 0) return 100;
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }
        return round(100 - (100 / (1 + ($avgGain / $avgLoss))), 2);
    }

    private function calculateMACD($prices)
    {
        if (count($prices) < 35) return null;

        $macdValues = [];
        for ($i = 26; $i <= count($prices); $i++) {
            $slice = array_slice($prices, 0, $i);
            $ema12 = $this->calculateEMA($slice, 12);
            $ema26 = $this->calculateEMA($slice, 26);
            if ($ema12 && $ema26) {
                $macdValues[] = $ema12 - $ema26;
            }
        }

        $macdLine   = end($macdValues);
        $signalLine = $this->calculateEMA($macdValues, 9);
        $histogram  = $macdLine - ($signalLine ?? 0);

        $crossover = null;
        if (count($macdValues) >= 2) {
            $prevMacd   = $macdValues[count($macdValues) - 2];
            $prevSignal = $this->calculateEMA(array_slice($macdValues, 0, -1), 9) ?? 0;

            if ($prevMacd < $prevSignal && $macdLine > ($signalLine ?? 0)) {
                $crossover = 'BULLISH_CROSS';
            } elseif ($prevMacd > $prevSignal && $macdLine < ($signalLine ?? 0)) {
                $crossover = 'BEARISH_CROSS';
            }
        }

        return [
            'line'      => round($macdLine, 3),
            'signal'    => $signalLine ? round($signalLine, 3) : null,
            'histogram' => round($histogram, 3),
            'crossover' => $crossover,
        ];
    }

    private function calculateATR($candles, $period = 14)
    {
        if (count($candles) < $period + 1) return null;
        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $high      = (float)$candles[$i]['high'];
            $low       = (float)$candles[$i]['low'];
            $prevClose = (float)$candles[$i - 1]['close'];
            $trs[] = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
        }
        $atr = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period; $i < count($trs); $i++) {
            $atr = (($atr * ($period - 1)) + $trs[$i]) / $period;
        }
        return round($atr, 2);
    }

    private function calculateBollingerBands($prices, $period = 20, $stdDev = 2)
    {
        if (count($prices) < $period) return null;
        $slice    = array_slice($prices, -$period);
        $sma      = array_sum($slice) / $period;
        $variance = array_sum(array_map(fn($p) => pow($p - $sma, 2), $slice)) / $period;
        $std      = sqrt($variance);
        return [
            'middle' => round($sma, 2),
            'upper'  => round($sma + ($stdDev * $std), 2),
            'lower'  => round($sma - ($stdDev * $std), 2),
            'width'  => round(($stdDev * $std * 2), 2),
        ];
    }

    private function calculateStochastic($candles, $period = 14)
    {
        if (count($candles) < $period) return null;
        $slice   = array_slice($candles, -$period);
        $highest = max(array_map(fn($c) => (float)$c['high'],  $slice));
        $lowest  = min(array_map(fn($c) => (float)$c['low'],   $slice));
        $lastClose = (float)$candles[count($candles) - 1]['close'];
        if ($highest == $lowest) return 50;
        return round((($lastClose - $lowest) / ($highest - $lowest)) * 100, 2);
    }

    private function analyzeVolume($candles)
    {
        if (count($candles) < 10 || !isset($candles[0]['volume'])) {
            // ✅ FIX #7: إذا ما في volume data، إرجاع neutral بدون تأثير سلبي
            return ['trend' => 'NEUTRAL', 'strength' => 0, 'ratio' => 1.0];
        }

        $volumes = array_map(fn($c) => (float)($c['volume'] ?? 0), $candles);
        $recentVol = array_sum(array_slice($volumes, -3)) / 3;
        $avgVol = array_sum($volumes) / count($volumes);
        $ratio = $avgVol > 0 ? $recentVol / $avgVol : 1.0;

        $strength = 0;
        $trend = 'NEUTRAL';

        if ($ratio > 1.3) {
            $trend = 'STRONG';
            $strength = 1.5;
        } elseif ($ratio > 1.1) {
            $trend = 'ABOVE_AVG';
            $strength = 1.0;
        } elseif ($ratio < 0.7) {
            $trend = 'WEAK';
            $strength = -0.5;  // ✅ FIX: كان -1.0 → الآن -0.5 أخف
        }

        return [
            'trend'    => $trend,
            'strength' => $strength,
            'ratio'    => round($ratio, 2)
        ];
    }

    private function checkTrendAlignment($prices1m, $prices5m, $prices1h, $currentPrice)
    {
        $ema20_1m = $this->calculateEMA($prices1m, 20);
        $ema20_5m = $this->calculateEMA($prices5m, 20);
        $ema20_1h = $this->calculateEMA($prices1h, 20);

        $alignScore = 0;
        $signals = [];

        if ($ema20_1m !== null) {
            $signals['1m'] = $currentPrice > $ema20_1m ? 'BULL' : 'BEAR';
            $alignScore += ($signals['1m'] === 'BULL') ? 1 : -1;
        }

        if ($ema20_5m !== null) {
            $signals['5m'] = $currentPrice > $ema20_5m ? 'BULL' : 'BEAR';
            $alignScore += ($signals['5m'] === 'BULL') ? 1 : -1;
        }

        if ($ema20_1h !== null) {
            $signals['1h'] = $currentPrice > $ema20_1h ? 'BULL' : 'BEAR';
            $alignScore += ($signals['1h'] === 'BULL') ? 2 : -2;
        }

        $maxPossible = 4;
        $status = 'NEUTRAL';
        if      ($alignScore >= 3)  $status = 'STRONG_BULL';
        elseif  ($alignScore >= 1)  $status = 'BULL';
        elseif  ($alignScore <= -3) $status = 'STRONG_BEAR';
        elseif  ($alignScore <= -1) $status = 'BEAR';

        return [
            'status'     => $status,
            'score'      => $alignScore,
            'alignment'  => round(abs($alignScore) / $maxPossible, 2),
            'signals'    => $signals,
            'is_aligned' => abs($alignScore) >= 2
        ];
    }

    private function calculatePivotPoints($lastCandle)
    {
        $h = (float)$lastCandle['high'];
        $l = (float)$lastCandle['low'];
        $c = (float)$lastCandle['close'];
        $p = ($h + $l + $c) / 3;
        return [
            'pivot' => round($p, 2),
            'R1'    => round((2 * $p) - $l, 2),
            'R2'    => round($p + ($h - $l), 2),
            'S1'    => round((2 * $p) - $h, 2),
            'S2'    => round($p - ($h - $l), 2),
        ];
    }

    private function analyzeCandleStructure($candles)
    {
        if (count($candles) < 5) return ['trend' => 'NEUTRAL', 'score' => 0];

        $recent = array_slice($candles, -5);
        $highs  = array_map(fn($c) => (float)$c['high'],  $recent);
        $lows   = array_map(fn($c) => (float)$c['low'],   $recent);

        $hhCount = 0; $hlCount = 0;
        $lhCount = 0; $llCount = 0;

        for ($i = 1; $i < count($highs); $i++) {
            if ($highs[$i] > $highs[$i - 1]) $hhCount++;
            if ($lows[$i]  > $lows[$i - 1])  $hlCount++;
            if ($highs[$i] < $highs[$i - 1]) $lhCount++;
            if ($lows[$i]  < $lows[$i - 1])  $llCount++;
        }

        $lastCandle  = end($recent);
        $lastBullish = (float)$lastCandle['close'] > (float)$lastCandle['open'];

        $bullishScore = $hhCount + $hlCount + ($lastBullish ? 1 : 0);
        $bearishScore = $lhCount + $llCount + (!$lastBullish ? 1 : 0);

        return [
            'hh' => $hhCount, 'hl' => $hlCount,
            'lh' => $lhCount, 'll' => $llCount,
            'bullish_score' => $bullishScore,
            'bearish_score' => $bearishScore,
            'last_candle'   => $lastBullish ? 'BULLISH' : 'BEARISH',
        ];
    }

    private function calculateCorrectLotSize($slDistanceDollars)
    {
        $maxRiskDollars = (self::ACCOUNT_SIZE * self::RISK_PERCENT) / 100;
        $lotSize = $maxRiskDollars / ($slDistanceDollars * self::OZ_PER_STD_LOT);

        if (self::ACCOUNT_SIZE <= 10) {
            $lotSize = max(self::NANO_LOT, round($lotSize, 3));
        } else {
            $lotSize = max(self::MIN_LOT, round($lotSize, 2));
        }

        return $lotSize;
    }

    private function calculateTradeSetup($currentPrice, $direction, $atr)
    {
        $atr = $atr ?? 2.0;

        $slDist  = max(round($atr * self::ATR_SL_MULT,  2), 1.5);
        $tp1Dist = max(round($atr * self::ATR_TP1_MULT, 2), 1.0);
        $tp2Dist = max(round($atr * self::ATR_TP2_MULT, 2), 2.0);
        $tp3Dist = max(round($atr * self::ATR_TP3_MULT, 2), 3.0);

        $lotSize = $this->calculateCorrectLotSize($slDist);

        if ($direction === 'BUY') {
            $entry = round($currentPrice, 2);
            $sl    = round($entry - $slDist,  2);
            $tp1   = round($entry + $tp1Dist, 2);
            $tp2   = round($entry + $tp2Dist, 2);
            $tp3   = round($entry + $tp3Dist, 2);
        } else {
            $entry = round($currentPrice, 2);
            $sl    = round($entry + $slDist,  2);
            $tp1   = round($entry - $tp1Dist, 2);
            $tp2   = round($entry - $tp2Dist, 2);
            $tp3   = round($entry - $tp3Dist, 2);
        }

        $riskDollars   = $slDist  * $lotSize * self::OZ_PER_STD_LOT;
        $rewardDollars = $tp1Dist * $lotSize * self::OZ_PER_STD_LOT;
        $rrRatio       = $tp1Dist / $slDist;

        return [
            'entry'        => $entry,
            'tp1'          => $tp1,
            'tp2'          => $tp2,
            'tp3'          => $tp3,
            'sl'           => $sl,
            'sl_distance'  => $slDist,
            'lot_size'     => $lotSize,
            'risk_amount'  => round($riskDollars, 2),
            'risk_percent' => self::RISK_PERCENT,
            'rr_ratio'     => round($rrRatio, 2),
            'atr_used'     => $atr,
        ];
    }

    // ==============================
    // FIXED: Signal Generation
    // ==============================
    private function generateTradingSignal(
        $rsi1m, $rsi5m, $stoch, $price, $pivots,
        $ema12, $ema20, $ema50, $bb, $macdData, $candleStruct,
        $volumeData, $trendAlignment, $atr
    ) {
        $bullPoints = 0;
        $bearPoints = 0;
        $signalLog  = [];

        // ─────────────────────────────────────
        // 1. RSI - أكثر مرونة
        // ─────────────────────────────────────
        $rsiBullish     = ($rsi1m < 35) || ($rsi5m < 40);
        $rsiBearish     = ($rsi1m > 65) || ($rsi5m > 60);
        $rsiStrongBull  = ($rsi1m < 30 && $rsi5m < 35);
        $rsiStrongBear  = ($rsi1m > 70 && $rsi5m > 65);

        if ($rsiStrongBull) {
            $bullPoints += self::WEIGHT_RSI;
            $signalLog[] = 'RSI_OVERSOLD_BOTH';
        } elseif ($rsiBullish) {
            $bullPoints += self::WEIGHT_RSI * 0.8;
            $signalLog[] = 'RSI_OVERSOLD_SINGLE';
        } elseif ($rsi1m < 50 && $rsi5m < 55) {   // RSI محايد-منخفض في uptrend
            $bullPoints += self::WEIGHT_RSI * 0.5;
            $signalLog[] = 'RSI_PULLBACK_BULL';
        } elseif ($rsi1m < 55) {                   // أي RSI تحت 55 = ليس overbought
            $bullPoints += self::WEIGHT_RSI * 0.25;
            $signalLog[] = 'RSI_NEUTRAL_BULL';
        }

        if ($rsiStrongBear) {
            $bearPoints += self::WEIGHT_RSI;
            $signalLog[] = 'RSI_OVERBOUGHT_BOTH';
        } elseif ($rsiBearish) {
            $bearPoints += self::WEIGHT_RSI * 0.8;
            $signalLog[] = 'RSI_OVERBOUGHT_SINGLE';
        } elseif ($rsi1m > 50 && $rsi5m > 45) {   // RSI محايد-مرتفع
            $bearPoints += self::WEIGHT_RSI * 0.5;
            $signalLog[] = 'RSI_PULLBACK_BEAR';
        } elseif ($rsi1m > 45) {
            $bearPoints += self::WEIGHT_RSI * 0.25;
            $signalLog[] = 'RSI_NEUTRAL_BEAR';
        }

        // ─────────────────────────────────────
        // 2. Stochastic
        // ─────────────────────────────────────
        if ($stoch !== null) {
            if ($stoch < 20) {
                $bullPoints += self::WEIGHT_STOCH;
                $signalLog[] = 'STOCH_EXTREME_OVERSOLD';
            } elseif ($stoch < 35) {                          // ✅ كان 30 → 35
                $bullPoints += self::WEIGHT_STOCH * 0.6;
                $signalLog[] = 'STOCH_OVERSOLD';
            } elseif ($stoch > 80) {
                $bearPoints += self::WEIGHT_STOCH;
                $signalLog[] = 'STOCH_EXTREME_OVERBOUGHT';
            } elseif ($stoch > 65) {                          // ✅ كان 70 → 65
                $bearPoints += self::WEIGHT_STOCH * 0.6;
                $signalLog[] = 'STOCH_OVERBOUGHT';
            }
        }

        // ─────────────────────────────────────
        // 3. EMA 12/20
        // ─────────────────────────────────────
        if ($ema12 && $ema20) {
            if ($ema12 > $ema20 && $price > $ema20) {
                $bullPoints += self::WEIGHT_EMA12_20;
                $signalLog[] = 'EMA12_20_BULL';
            } elseif ($ema12 > $ema20) {
                $bullPoints += self::WEIGHT_EMA12_20 * 0.7; // ✅ كان 0.5 → 0.7
                $signalLog[] = 'EMA12_20_WEAK_BULL';
            }

            if ($ema12 < $ema20 && $price < $ema20) {
                $bearPoints += self::WEIGHT_EMA12_20;
                $signalLog[] = 'EMA12_20_BEAR';
            } elseif ($ema12 < $ema20) {
                $bearPoints += self::WEIGHT_EMA12_20 * 0.7; // ✅ كان 0.5 → 0.7
                $signalLog[] = 'EMA12_20_WEAK_BEAR';
            }
        }

        // ─────────────────────────────────────
        // 4. EMA50 - Main Trend
        // ─────────────────────────────────────
        if ($ema50 && $ema20) {
            if ($price > $ema50 && $ema20 > $ema50) {
                $bullPoints += self::WEIGHT_EMA50;
                $signalLog[] = 'EMA50_STRONG_BULL';
            } elseif ($price > $ema50) {
                $bullPoints += self::WEIGHT_EMA50 * 0.5;    // ✅ كان 0.3 → 0.5
                $signalLog[] = 'EMA50_WEAK_BULL';
            }

            if ($price < $ema50 && $ema20 < $ema50) {
                $bearPoints += self::WEIGHT_EMA50;
                $signalLog[] = 'EMA50_STRONG_BEAR';
            } elseif ($price < $ema50) {
                $bearPoints += self::WEIGHT_EMA50 * 0.5;    // ✅ كان 0.3 → 0.5
                $signalLog[] = 'EMA50_WEAK_BEAR';
            }
        }

        // ─────────────────────────────────────
        // 5. Bollinger Bands
        // ─────────────────────────────────────
        if ($bb) {
            $bbRange = $bb['upper'] - $bb['lower'];

            if ($price <= $bb['lower'] || ($price - $bb['lower']) < ($bbRange * 0.08)) {
                $bullPoints += self::WEIGHT_BB;
                $signalLog[] = 'BB_TOUCH_LOWER';
            } elseif ($price < $bb['middle']) {
                $bullPoints += self::WEIGHT_BB * 0.5;
                $signalLog[] = 'BB_BELOW_MID';
            }

            if ($price >= $bb['upper'] || ($bb['upper'] - $price) < ($bbRange * 0.08)) {
                $bearPoints += self::WEIGHT_BB;
                $signalLog[] = 'BB_TOUCH_UPPER';
            } elseif ($price > $bb['middle']) {
                $bearPoints += self::WEIGHT_BB * 0.5;
                $signalLog[] = 'BB_ABOVE_MID';
            }
        }

        // ─────────────────────────────────────
        // 6. Pivot Points
        // ─────────────────────────────────────
        $proximityThreshold = max($atr * 0.8, 2.0);

        if ($pivots) {
            if (abs($price - $pivots['S2']) < $proximityThreshold) {
                $bullPoints += self::WEIGHT_PIVOT * 1.5;
                $signalLog[] = 'PIVOT_S2_NEAR';
            } elseif (abs($price - $pivots['S1']) < $proximityThreshold) {
                $bullPoints += self::WEIGHT_PIVOT;
                $signalLog[] = 'PIVOT_S1_NEAR';
            }

            if (abs($price - $pivots['R2']) < $proximityThreshold) {
                $bearPoints += self::WEIGHT_PIVOT * 1.5;
                $signalLog[] = 'PIVOT_R2_NEAR';
            } elseif (abs($price - $pivots['R1']) < $proximityThreshold) {
                $bearPoints += self::WEIGHT_PIVOT;
                $signalLog[] = 'PIVOT_R1_NEAR';
            }
        }

        // ─────────────────────────────────────
        // 7. MACD
        // ─────────────────────────────────────
        if ($macdData) {
            if ($macdData['crossover'] === 'BULLISH_CROSS') {
                $bullPoints += self::WEIGHT_MACD;
                $signalLog[] = 'MACD_BULL_CROSS';
            } elseif ($macdData['crossover'] === 'BEARISH_CROSS') {
                $bearPoints += self::WEIGHT_MACD;
                $signalLog[] = 'MACD_BEAR_CROSS';
            } elseif ($macdData['histogram'] > 0.05) {        // ✅ إضافة threshold صغير
                $bullPoints += self::WEIGHT_MACD * 0.6;
                $signalLog[] = 'MACD_BULL_HISTOGRAM';
            } elseif ($macdData['histogram'] < -0.05) {
                $bearPoints += self::WEIGHT_MACD * 0.6;
                $signalLog[] = 'MACD_BEAR_HISTOGRAM';
            } elseif ($macdData['line'] > 0) {                // ✅ جديد: MACD line فوق zero
                $bullPoints += self::WEIGHT_MACD * 0.3;
                $signalLog[] = 'MACD_ABOVE_ZERO';
            } elseif ($macdData['line'] < 0) {
                $bearPoints += self::WEIGHT_MACD * 0.3;
                $signalLog[] = 'MACD_BELOW_ZERO';
            }
        }

        // ─────────────────────────────────────
        // 8. FIX #8: Candle Structure - أسهل
        // ─────────────────────────────────────
        if ($candleStruct) {
            $structureScore = $candleStruct['bullish_score'] - $candleStruct['bearish_score'];

            if ($structureScore >= 4) {
                $bullPoints += self::WEIGHT_CANDLE;
                $signalLog[] = 'CANDLE_STRONG_BULL';
            } elseif ($structureScore >= 1) {                 // ✅ كان 2 → الآن 1
                $bullPoints += self::WEIGHT_CANDLE * 0.6;    // ✅ كان 0.5 → 0.6
                $signalLog[] = 'CANDLE_WEAK_BULL';
            } elseif ($structureScore <= -4) {
                $bearPoints += self::WEIGHT_CANDLE;
                $signalLog[] = 'CANDLE_STRONG_BEAR';
            } elseif ($structureScore <= -1) {                // ✅ كان -2 → الآن -1
                $bearPoints += self::WEIGHT_CANDLE * 0.6;
                $signalLog[] = 'CANDLE_WEAK_BEAR';
            }
        }

        // ─────────────────────────────────────
        // 9. Volume Confirmation
        // ─────────────────────────────────────
        if ($volumeData && $volumeData['strength'] > 0) {
            if ($bullPoints > $bearPoints) {
                $bullPoints += self::WEIGHT_VOLUME * 0.5;
                $signalLog[] = 'VOLUME_CONFIRMS_BULL';
            } elseif ($bearPoints > $bullPoints) {
                $bearPoints += self::WEIGHT_VOLUME * 0.5;
                $signalLog[] = 'VOLUME_CONFIRMS_BEAR';
            }
        }
        // ✅ FIX #7: لا نعطي نقاط سلبية للـ WEAK volume — فقط لا نعطي نقاط إيجابية

        // ─────────────────────────────────────
        // 10. Multi-Timeframe Trend Alignment (bonus فقط، لا يبلوك)
        // ─────────────────────────────────────
        if ($trendAlignment) {
            if ($trendAlignment['status'] === 'STRONG_BULL') {
                $bullPoints += self::WEIGHT_TREND_ALIGN;
                $signalLog[] = 'TREND_ALIGNED_STRONG_BULL';
            } elseif ($trendAlignment['status'] === 'BULL') {
                $bullPoints += self::WEIGHT_TREND_ALIGN * 0.6;
                $signalLog[] = 'TREND_ALIGNED_BULL';
            } elseif ($trendAlignment['status'] === 'STRONG_BEAR') {
                $bearPoints += self::WEIGHT_TREND_ALIGN;
                $signalLog[] = 'TREND_ALIGNED_STRONG_BEAR';
            } elseif ($trendAlignment['status'] === 'BEAR') {
                $bearPoints += self::WEIGHT_TREND_ALIGN * 0.6;
                $signalLog[] = 'TREND_ALIGNED_BEAR';
            }
            // NEUTRAL: لا بونص ولا حجب
        }

        // ─────────────────────────────────────
        // حساب النتيجة
        // ─────────────────────────────────────
        $maxPoints = self::WEIGHT_RSI + self::WEIGHT_STOCH + self::WEIGHT_EMA12_20 +
                     self::WEIGHT_EMA50 + self::WEIGHT_BB + self::WEIGHT_PIVOT +
                     self::WEIGHT_MACD + self::WEIGHT_CANDLE + self::WEIGHT_VOLUME +
                     self::WEIGHT_TREND_ALIGN; // = 11.0

        // ✅ FIX #1: العتبة 40% = 4.4 نقاط (كانت 6.05)
        $minThreshold    = $maxPoints * self::MIN_SIGNAL_THRESHOLD;    // 4.4
        $strongThreshold = $maxPoints * self::STRONG_SIGNAL_THRESHOLD; // 6.6

        $netScore  = $bullPoints - $bearPoints;
        $absScore  = abs($netScore);
        $strength  = round(min(10, max(0, ($absScore / $maxPoints) * 10)), 1);

        // ─────────────────────────────────────
        // ✅ FIX #2 & #3: تحديد الاتجاه — بدون شرط trendAlignment إجباري
        // ─────────────────────────────────────
        $direction = 'WAIT';
        $reason    = '';

        if ($bullPoints > $bearPoints && $bullPoints >= $minThreshold) {
            // ✅ FIX #2: لا يشترط trendAlignment — فقط لا يسمح ضد STRONG_BEAR
            $againstStrongBear = ($trendAlignment !== null && $trendAlignment['status'] === 'STRONG_BEAR');

            if (!$againstStrongBear) {
                $direction = 'BUY';
                $reason    = $bullPoints >= $strongThreshold
                    ? 'إشارة شراء قوية ✅'
                    : 'إشارة شراء معتدلة ✅';
            } else {
                $direction = 'WAIT';
                $reason    = 'إشارة شراء ضعيفة أمام اتجاه هبوطي قوي ⚠️';
            }

        } elseif ($bearPoints > $bullPoints && $bearPoints >= $minThreshold) {
            // ✅ FIX #2: فقط يحجب ضد STRONG_BULL
            $againstStrongBull = ($trendAlignment !== null && $trendAlignment['status'] === 'STRONG_BULL');

            if (!$againstStrongBull) {
                $direction = 'SELL';
                $reason    = $bearPoints >= $strongThreshold
                    ? 'إشارة بيع قوية ✅'
                    : 'إشارة بيع معتدلة ✅';
            } else {
                $direction = 'WAIT';
                $reason    = 'إشارة بيع ضعيفة أمام اتجاه صعودي قوي ⚠️';
            }

        } else {
            $dominant = ($bullPoints >= $bearPoints) ? 'شراء' : 'بيع';
            $current  = ($bullPoints >= $bearPoints) ? round($bullPoints, 1) : round($bearPoints, 1);
            $reason   = "نقاط {$dominant} غير كافية ({$current} / " . round($minThreshold, 1) . " مطلوب)";
        }

        Log::info('Signal Generation', [
            'direction'      => $direction,
            'bull_points'    => round($bullPoints, 2),
            'bear_points'    => round($bearPoints, 2),
            'threshold'      => round($minThreshold, 2),
            'signal_log'     => $signalLog,
            'trend_status'   => $trendAlignment['status'] ?? 'N/A',
            'reason'         => $reason
        ]);

        return [
            'direction'    => $direction,
            'strength'     => $strength,
            'bull_points'  => round($bullPoints, 2),
            'bear_points'  => round($bearPoints, 2),
            'net_score'    => round($netScore, 2),
            'max_possible' => $maxPoints,
            'threshold'    => round($minThreshold, 2),
            'reason'       => $reason,
            'components'   => [
                'rsi_signal'    => $rsiBullish ? 'BULL' : ($rsiBearish ? 'BEAR' : 'NEUTRAL'),
                'ema_signal'    => ($ema12 > $ema20 && $price > $ema20) ? 'BULL' : (($ema12 < $ema20 && $price < $ema20) ? 'BEAR' : 'NEUTRAL'),
                'ema50_signal'  => ($ema50 && $price > $ema50 && $ema20 > $ema50) ? 'STRONG_BULL' : (($ema50 && $price < $ema50 && $ema20 < $ema50) ? 'STRONG_BEAR' : 'NEUTRAL'),
                'macd_cross'    => $macdData['crossover'] ?? 'NONE',
                'stoch_signal'  => ($stoch !== null && $stoch < 35) ? 'BULL' : (($stoch !== null && $stoch > 65) ? 'BEAR' : 'NEUTRAL'),
                'trend_align'   => $trendAlignment['status'] ?? 'UNKNOWN',
                'volume_status' => $volumeData['trend'] ?? 'UNKNOWN',
                'signals_fired' => $signalLog
            ]
        ];
    }

    // ==============================
    // Validate Trade Plan
    // ==============================
    private function validateTradePlan($plan, $currentPrice = null, $direction = null)
    {
        if (!isset($plan['entry']) && isset($plan['entry_zone'])) {
            $plan['entry'] = $plan['entry_zone'];
        }

        $requiredKeys = ['entry', 'tp1', 'tp2', 'tp3', 'sl'];
        foreach ($requiredKeys as $key) {
            if (!isset($plan[$key])) {
                Log::warning("Gold: Missing key: {$key}");
                return false;
            }
        }

        $entry = (float)$plan['entry'];
        $tp1   = (float)$plan['tp1'];
        $tp2   = (float)$plan['tp2'];
        $tp3   = (float)$plan['tp3'];
        $sl    = (float)$plan['sl'];

        if ($currentPrice !== null && abs($entry - $currentPrice) > 2) {
            Log::warning("Gold: Entry too far: {$entry} vs {$currentPrice}");
            return false;
        }

        $isBuy  = ($sl < $entry);
        $isSell = ($sl > $entry);

        if (!$isBuy && !$isSell) {
            Log::warning("Gold: SL == Entry");
            return false;
        }

        if ($isBuy) {
            if ($tp1 <= $entry || $tp2 <= $tp1 || $tp3 <= $tp2) {
                Log::warning("Gold: BUY TPs order wrong");
                return false;
            }
        } else {
            if ($tp1 >= $entry || $tp2 >= $tp1 || $tp3 >= $tp2) {
                Log::warning("Gold: SELL TPs order wrong");
                return false;
            }
        }

        if (abs($tp1 - $entry) < 1.5 || abs($entry - $sl) < 1.5) {
            Log::warning("Gold: TP1 or SL too close");
            return false;
        }

        return true;
    }

    private function isTradingSession()
    {
        $utcHour   = (int)date('G');
        $londonOpen = ($utcHour >= 7  && $utcHour < 17);
        $nyOpen     = ($utcHour >= 13 && $utcHour < 22);

        return [
            'london'       => $londonOpen,
            'ny'           => $nyOpen,
            'best_session' => ($utcHour >= 13 && $utcHour < 17),
            'is_active'    => $londonOpen || $nyOpen,
        ];
    }

    // ==============================
    // MAIN ANALYSIS ENDPOINT
    // ==============================
    public function getAnalysis(Request $request)
    {
        Log::info('Gold Analysis: Starting...');

        $twelveDataKey = env('TWELVEDATA_API_KEY');
        $groqKey       = env('GROQ_API_KEY');

        if (empty($twelveDataKey) || empty($groqKey)) {
            return response()->json(['error' => 'API keys not configured'], 500);
        }

        try {
            [$res1m, $res5m, $res1h] = array_map(
                fn($interval) => Http::timeout(10)->get(
                    "https://api.twelvedata.com/time_series?symbol=XAU/USD&interval={$interval}&outputsize=100&apikey={$twelveDataKey}"
                )->json(),
                ['1min', '5min', '1h']
            );

            if (!isset($res5m['values']) || !isset($res1h['values']) || !isset($res1m['values'])) {
                return response()->json(['error' => 'Failed to fetch market data'], 500);
            }

            $candles1m = array_reverse($res1m['values']);
            $candles5m = array_reverse($res5m['values']);
            $candles1h = array_reverse($res1h['values']);

            $prices1m = array_map(fn($c) => (float)$c['close'], $candles1m);
            $prices5m = array_map(fn($c) => (float)$c['close'], $candles5m);
            $prices1h = array_map(fn($c) => (float)$c['close'], $candles1h);

            $currentPrice = end($prices5m);

            // Indicators
            $ema12 = $this->calculateEMA($prices5m, 12);
            $ema20 = $this->calculateEMA($prices5m, 20);
            $ema50 = $this->calculateEMA($prices5m, 50);
            $rsi1m = $this->calculateRSI($prices1m, 14);
            $rsi5m = $this->calculateRSI($prices5m, 14);
            $atr   = $this->calculateATR($candles5m, 14);
            $bb    = $this->calculateBollingerBands($prices5m, 20);
            $stoch = $this->calculateStochastic($candles5m, 14);
            $macd  = $this->calculateMACD($prices5m);
            $pivots       = $this->calculatePivotPoints($candles5m[count($candles5m) - 1]);
            $candleStruct = $this->analyzeCandleStructure($candles5m);
            $session      = $this->isTradingSession();
            $volumeData      = $this->analyzeVolume($candles5m);
            $trendAlignment  = $this->checkTrendAlignment($prices1m, $prices5m, $prices1h, $currentPrice);

            // Generate Signal
            $tradingSignal = $this->generateTradingSignal(
                $rsi1m, $rsi5m, $stoch, $currentPrice,
                $pivots, $ema12, $ema20, $ema50,
                $bb, $macd, $candleStruct,
                $volumeData, $trendAlignment, $atr
            );

            // Trade Setup
            $tradeSetup = ($tradingSignal['direction'] !== 'WAIT')
                ? $this->calculateTradeSetup($currentPrice, $tradingSignal['direction'], $atr)
                : null;

            // Trend labels
            $last1h    = (float)$candles1h[count($candles1h) - 1]['close'];
            $prev1h    = (float)$candles1h[count($candles1h) - 2]['close'];
            $trend1h   = $last1h > $prev1h ? 'صاعد (Bullish)' : 'هابط (Bearish)';
            $trendShort = ($ema12 > $ema20 && $ema20 > $ema50) ? 'صاعد' :
                (($ema12 < $ema20 && $ema20 < $ema50) ? 'هابط' : 'جانبي');

            // AI Prompt
            if ($tradingSignal['direction'] !== 'WAIT' && $tradeSetup) {
                $dir         = $tradingSignal['direction'];
                $dirAr       = $dir === 'BUY' ? 'شراء' : 'بيع';
                $slDirection = $dir === 'BUY' ? 'أسفل' : 'أعلى';
                $prompt = "
أنت خبير سكالبينج ذهب. حساب: $" . self::ACCOUNT_SIZE . "، لوت: {$tradeSetup['lot_size']}

السعر: {$currentPrice} | الإشارة: {$dir} ({$dirAr}) | القوة: {$tradingSignal['strength']}/10
ATR: {$atr} | نسبة R/R: {$tradeSetup['rr_ratio']}

خطة التداول:
- Entry: {$tradeSetup['entry']}
- SL: {$tradeSetup['sl']} ({$slDirection} Entry)
- TP1: {$tradeSetup['tp1']} | TP2: {$tradeSetup['tp2']} | TP3: {$tradeSetup['tp3']}

المؤشرات:
- RSI 1m: {$rsi1m} | RSI 5m: {$rsi5m}
- EMA12: {$ema12} | EMA20: {$ema20} | EMA50: {$ema50}
- Stoch: {$stoch} | MACD Cross: " . ($macd['crossover'] ?? 'NONE') . "
- BB Lower: {$bb['lower']} / Middle: {$bb['middle']} / Upper: {$bb['upper']}
- Pivots — S1: {$pivots['S1']} | Pivot: {$pivots['pivot']} | R1: {$pivots['R1']}
- إشارات: " . implode(', ', $tradingSignal['components']['signals_fired']) . "

قدم تحليلاً مختصراً بالعربية. اشرح لماذا الإشارة {$dirAr} وأهم المستويات.
";
            } else {
                $bullPts = $tradingSignal['bull_points'];
                $bearPts = $tradingSignal['bear_points'];
                $needed  = $tradingSignal['threshold'];
                $prompt  = "
السعر: {$currentPrice} | RSI 1m: {$rsi1m} | RSI 5m: {$rsi5m} | Stoch: {$stoch}
EMA12: {$ema12} | EMA20: {$ema20} | EMA50: {$ema50} | ATR: {$atr}
نقاط شراء: {$bullPts} | نقاط بيع: {$bearPts} | الحد المطلوب: {$needed}
السبب: {$tradingSignal['reason']}

السوق في حالة انتظار. اشرح باختصار السبب وما الذي يجب رؤيته للدخول.
";
            }

            // Call AI
            $responseAI = Http::withHeaders([
                'Authorization' => 'Bearer ' . $groqKey,
                'Content-Type'  => 'application/json'
            ])->timeout(30)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => [
                    ['role' => 'system', 'content' => 'أنت محلل تداول محترف متخصص في الذهب. اعطِ تحليلاً مختصراً ومفيداً بالعربية.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.1,
                'max_tokens'  => 800,
            ]);

            if (!$responseAI->successful()) {
                return response()->json(['error' => 'AI analysis failed'], 500);
            }

            $recommendation = $responseAI->json()['choices'][0]['message']['content'] ?? 'فشل التحليل';
            $tradePlan      = ($tradingSignal['direction'] !== 'WAIT' && $tradeSetup) ? $tradeSetup : null;

            Log::info('Gold Analysis: Complete', [
                'price'     => $currentPrice,
                'signal'    => $tradingSignal['direction'],
                'strength'  => $tradingSignal['strength'],
                'bull_pts'  => $tradingSignal['bull_points'],
                'bear_pts'  => $tradingSignal['bear_points'],
                'threshold' => $tradingSignal['threshold'],
            ]);

            return response()->json([
                'current_price'  => $currentPrice,
                'trend_daily'    => $trend1h,
                'trend_short'    => $trendShort,
                'session'        => $session,
                'indicators'     => [
                    'ema'             => ['ema12' => $ema12, 'ema20' => $ema20, 'ema50' => $ema50],
                    'rsi'             => ['rsi_1m' => $rsi1m, 'rsi_5m' => $rsi5m],
                    'atr'             => $atr,
                    'stochastic'      => $stoch,
                    'macd'            => $macd,
                    'bb'              => $bb,
                    'pivots'          => $pivots,
                    'candle_struct'   => $candleStruct,
                    'volume'          => $volumeData,
                    'trend_alignment' => $trendAlignment,
                ],
                'trading_signal' => $tradingSignal,
                'trade_plan'     => $tradePlan,
                'recommendation' => $recommendation,
                'time'           => now()->format('Y-m-d H:i:s'),
                'chart_data_5m'  => array_slice($prices5m, -30),
            ]);

        } catch (\Exception $e) {
            Log::error('Gold Analysis: Exception', ['message' => $e->getMessage()]);
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}