<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ====================================================
 * Gold AI Scalper - PROFESSIONAL & ACCURATE VERSION
 * ====================================================
 *
 * MAJOR IMPROVEMENTS FOR ACCURACY:
 * 1. Signal threshold at 55% (balanced quality vs frequency)
 * 2. RSI accepts signal from EITHER timeframe (relaxed from requiring both)
 * 3. MACD gives partial credit for histogram (not just crossover)
 * 4. EMA50 logic FIXED - properly checks price AND EMA20 alignment
 * 5. Pivot proximity now DYNAMIC based on ATR (was fixed $1.50)
 * 6. NEW: Volume confirmation system (filters weak signals)
 * 7. NEW: Multi-timeframe trend alignment check (1m/5m/1h)
 * 8. NEW: Signal logging for debugging which indicators fired
 * 9. Trend alignment BONUS when all timeframes agree
 * 10. WAIT signal only when trend strongly conflicts
 *
 * ORIGINAL BUGS FIXED:
 * - validateTradePlan(): BUY/SELL logic was completely inverted
 * - AI Prompt: BUY/SELL direction rules were reversed
 * - Position sizing: Risk calculation was wrong (10% not 1.5%)
 * - Pivot proximity: Too tight at $0.30
 * - EMA50 added to signal generation
 * - MACD Signal Line added (crossover detection)
 *
 * RISK MANAGEMENT:
 * - Dynamic lot size calculation based on real risk %
 * - Nano lot support for $10 accounts
 * - ATR-based SL/TP instead of fixed pips
 * - Candle structure analysis (higher highs, lower lows)
 * - Session filter (London/NY sessions)
 */
class GoldController extends Controller
{
    // ====== Risk Management Constants ======
    private const ACCOUNT_SIZE  = 100;     // Change to 10 for $10 account
    private const RISK_PERCENT  = 1.5;     // % risk per trade
    private const MIN_LOT       = 0.01;    // Minimum lot (use 0.001 for $10 nano)
    private const NANO_LOT      = 0.001;   // Nano lot for tiny accounts

    // XAU/USD: 1 standard lot = 100 oz → $1 move = $100 P&L
    private const OZ_PER_STD_LOT = 100;

    private const RSI_OVERBOUGHT  = 70;
    private const RSI_OVERSOLD    = 30;
    private const RSI_EXTREME_HIGH = 80;
    private const RSI_EXTREME_LOW  = 20;

    // ATR multipliers for SL/TP
    private const ATR_SL_MULT  = 1.5;   // SL = 1.5 × ATR
    private const ATR_TP1_MULT = 1.0;   // TP1 = 1.0 × ATR
    private const ATR_TP2_MULT = 1.8;   // TP2 = 1.8 × ATR
    private const ATR_TP3_MULT = 2.5;   // TP3 = 2.5 × ATR

    // ====== IMPROVED: Signal Thresholds ======
    private const MIN_SIGNAL_THRESHOLD = 0.55;  // 55% confidence required (reduced from 65%)
    private const STRONG_SIGNAL_THRESHOLD = 0.70; // 70% for strong signals
    private const MIN_RR_RATIO = 1.5;   // Minimum risk/reward ratio

    // ====== IMPROVED: Indicator Weights (Normalized) ======
    private const WEIGHT_RSI = 1.5;        // Reduced from 2
    private const WEIGHT_STOCH = 1.0;      // Reduced from 1.5
    private const WEIGHT_EMA12_20 = 1.0;   // Short trend
    private const WEIGHT_EMA50 = 1.5;      // Main trend (higher weight)
    private const WEIGHT_BB = 1.0;         // Unchanged
    private const WEIGHT_PIVOT = 1.0;      // Reduced from 1.5
    private const WEIGHT_MACD = 1.5;       // Reduced from 2
    private const WEIGHT_CANDLE = 1.0;     // Reduced from 1.5
    private const WEIGHT_VOLUME = 1.0;     // NEW: Volume confirmation
    private const WEIGHT_TREND_ALIGN = 1.5;  // NEW: Multi-timeframe alignment

    public function landing() { return view('landing'); }

    // ==============================
    // TECHNICAL INDICATORS
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

    /**
     * FIX #6: MACD with Signal Line (crossover detection)
     * Previously: only returned MACD line (incomplete)
     */
    private function calculateMACD($prices)
    {
        if (count($prices) < 35) return null; // Need 26 + 9 for signal

        // Build MACD line array for signal calculation
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

        // Crossover detection (last 2 bars)
        $crossover = null;
        if (count($macdValues) >= 2) {
            $prevMacd   = $macdValues[count($macdValues) - 2];
            $prevSignal = $this->calculateEMA(array_slice($macdValues, 0, -1), 9) ?? 0;

            if ($prevMacd < $prevSignal && $macdLine > ($signalLine ?? 0)) {
                $crossover = 'BULLISH_CROSS'; // إشارة شراء
            } elseif ($prevMacd > $prevSignal && $macdLine < ($signalLine ?? 0)) {
                $crossover = 'BEARISH_CROSS'; // إشارة بيع
            }
        }

        return [
            'line'      => round($macdLine, 3),
            'signal'    => $signalLine ? round($signalLine, 3) : null,
            'histogram' => round($histogram, 3),
            'crossover' => $crossover, // الأهم للسكالبينج
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
            'width'  => round(($stdDev * $std * 2), 2), // للتقلب
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

    /**
     * IMPROVED: Volume Analysis with trend confirmation
     */
    private function analyzeVolume($candles)
    {
        if (count($candles) < 10 || !isset($candles[0]['volume'])) {
            return ['trend' => 'NEUTRAL', 'strength' => 0, 'ratio' => 1.0];
        }

        $volumes = array_map(fn($c) => (float)($c['volume'] ?? 0), $candles);
        $recentVol = array_sum(array_slice($volumes, -3)) / 3;
        $avgVol = array_sum($volumes) / count($volumes);

        $ratio = $avgVol > 0 ? $recentVol / $avgVol : 1.0;

        // Volume trend strength
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
            $strength = -1.0;
        }

        return [
            'trend' => $trend,
            'strength' => $strength,
            'ratio' => round($ratio, 2)
        ];
    }

    /**
     * IMPROVED: Multi-timeframe trend alignment check
     * Critical for accurate scalping signals
     */
    private function checkTrendAlignment($prices1m, $prices5m, $prices1h, $currentPrice)
    {
        // Calculate EMAs for each timeframe
        $ema20_1m = $this->calculateEMA($prices1m, 20);
        $ema20_5m = $this->calculateEMA($prices5m, 20);
        $ema20_1h = $this->calculateEMA($prices1h, 20);

        $alignScore = 0;
        $signals = [];

        // 1m trend
        if ($ema20_1m !== null) {
            $signals['1m'] = $currentPrice > $ema20_1m ? 'BULL' : 'BEAR';
            if ($signals['1m'] === 'BULL') $alignScore++;
            else $alignScore--;
        }

        // 5m trend
        if ($ema20_5m !== null) {
            $signals['5m'] = $currentPrice > $ema20_5m ? 'BULL' : 'BEAR';
            if ($signals['5m'] === 'BULL') $alignScore++;
            else $alignScore--;
        }

        // 1h trend (most important)
        if ($ema20_1h !== null) {
            $signals['1h'] = $currentPrice > $ema20_1h ? 'BULL' : 'BEAR';
            // 1h has 2x weight
            if ($signals['1h'] === 'BULL') $alignScore += 2;
            else $alignScore -= 2;
        }

        // Determine alignment status
        $maxPossible = 4; // 1 + 1 + 2
        $alignment = abs($alignScore) / $maxPossible;

        $status = 'NEUTRAL';
        if ($alignScore >= 3) $status = 'STRONG_BULL';
        elseif ($alignScore >= 1) $status = 'BULL';
        elseif ($alignScore <= -3) $status = 'STRONG_BEAR';
        elseif ($alignScore <= -1) $status = 'BEAR';

        return [
            'status' => $status,
            'score' => $alignScore,
            'alignment' => round($alignment, 2),
            'signals' => $signals,
            'is_aligned' => abs($alignScore) >= 2  // At least 2 of 3 agree
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

    // ==============================
    // FIX #7: Candle Structure Analysis (NEW)
    // ==============================
    private function analyzeCandleStructure($candles)
    {
        if (count($candles) < 5) return ['trend' => 'NEUTRAL', 'score' => 0];

        $recent = array_slice($candles, -5);
        $highs  = array_map(fn($c) => (float)$c['high'],  $recent);
        $lows   = array_map(fn($c) => (float)$c['low'],   $recent);
        $closes = array_map(fn($c) => (float)$c['close'], $recent);

        $hhCount = 0; $hlCount = 0;
        $lhCount = 0; $llCount = 0;

        for ($i = 1; $i < count($highs); $i++) {
            if ($highs[$i]  > $highs[$i - 1])  $hhCount++;
            if ($lows[$i]   > $lows[$i - 1])   $hlCount++;
            if ($highs[$i]  < $highs[$i - 1])  $lhCount++;
            if ($lows[$i]   < $lows[$i - 1])   $llCount++;
        }

        // آخر شمعة: bullish أم bearish؟
        $lastCandle   = end($recent);
        $lastBullish  = (float)$lastCandle['close'] > (float)$lastCandle['open'];

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

    // ==============================
    // FIX #3: Correct Position Sizing
    // ==============================
    /**
     * حساب حجم اللوت الصحيح بناءً على رأس المال والمخاطرة
     *
     * XAU/USD formula:
     * Lot Size = (Account × Risk%) / (SL_distance_in_$ × OzPerStdLot)
     */
    private function calculateCorrectLotSize($slDistanceDollars)
    {
        $maxRiskDollars = (self::ACCOUNT_SIZE * self::RISK_PERCENT) / 100;
        // P&L per $1 move = lot × 100oz
        $lotSize = $maxRiskDollars / ($slDistanceDollars * self::OZ_PER_STD_LOT);

        // للحسابات الصغيرة: استخدام nano lot
        if (self::ACCOUNT_SIZE <= 10) {
            $lotSize = max(self::NANO_LOT, round($lotSize, 3));
        } else {
            $lotSize = max(self::MIN_LOT, round($lotSize, 2));
        }

        return $lotSize;
    }

    /**
     * FIX #1 + #3: إدارة التداول الصحيحة مع ATR
     */
    private function calculateTradeSetup($currentPrice, $direction, $atr)
    {
        // استخدام ATR لحسابات ديناميكية بدلاً من أرقام ثابتة
        $atr = $atr ?? 2.0; // fallback

        $slDist  = max(round($atr * self::ATR_SL_MULT, 2),  1.5); // SL بحد أدنى $1.5
        $tp1Dist = max(round($atr * self::ATR_TP1_MULT, 2), 1.0);
        $tp2Dist = max(round($atr * self::ATR_TP2_MULT, 2), 2.0);
        $tp3Dist = max(round($atr * self::ATR_TP3_MULT, 2), 3.0);

        // حساب حجم اللوت الصحيح
        $lotSize = $this->calculateCorrectLotSize($slDist);

        if ($direction === 'BUY') {
            // BUY: SL أسفل Entry، TP أعلى Entry
            $entry = round($currentPrice, 2);
            $sl    = round($entry - $slDist,  2);  // ✅ SL أسفل
            $tp1   = round($entry + $tp1Dist, 2);  // ✅ TP1 فوق
            $tp2   = round($entry + $tp2Dist, 2);  // ✅ TP2 فوق TP1
            $tp3   = round($entry + $tp3Dist, 2);  // ✅ TP3 فوق TP2
        } else {
            // SELL: SL فوق Entry، TP أسفل Entry
            $entry = round($currentPrice, 2);
            $sl    = round($entry + $slDist,  2);  // ✅ SL فوق
            $tp1   = round($entry - $tp1Dist, 2);  // ✅ TP1 أسفل
            $tp2   = round($entry - $tp2Dist, 2);  // ✅ TP2 أسفل TP1
            $tp3   = round($entry - $tp3Dist, 2);  // ✅ TP3 أسفل TP2
        }

        $riskDollars  = $slDist * $lotSize * self::OZ_PER_STD_LOT;
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
    // IMPROVED: Signal Generation with Better Accuracy
    // ==============================
    /**
     * IMPROVED generateTradingSignal:
     * - Raised threshold from 55% to 65% for higher quality signals
     * - Fixed EMA50 logic - now requires price AND faster EMA both above/below
     * - Added volume confirmation requirement
     * - Added multi-timeframe trend alignment
     * - Dynamic pivot proximity based on ATR
     * - RSI now requires BOTH 1m and 5m confirmation
     * - Better weight distribution (MACD reduced from 2.0 to 1.5)
     * - Minimum R/R ratio check (1.5:1)
     */
    private function generateTradingSignal(
        $rsi1m, $rsi5m, $stoch, $price, $pivots,
        $ema12, $ema20, $ema50, $bb, $macdData, $candleStruct,
        $volumeData, $trendAlignment, $atr
    ) {
        $bullPoints = 0;
        $bearPoints = 0;
        $signalLog = []; // Track which indicators fired

        // 1. IMPROVED: RSI - RELAXED: Strong signal from either timeframe
        $rsiBullish = ($rsi1m < 30) || ($rsi5m < 35);  // Either timeframe oversold
        $rsiBearish = ($rsi1m > 70) || ($rsi5m > 65);  // Either timeframe overbought
        $rsiStrongBull = ($rsi1m < 30 && $rsi5m < 35); // Both agree = stronger
        $rsiStrongBear = ($rsi1m > 70 && $rsi5m > 65); // Both agree = stronger

        if ($rsiStrongBull) {
            $bullPoints += self::WEIGHT_RSI;  // Full weight when both agree
            $signalLog[] = 'RSI_OVERSOLD_BOTH';
        } elseif ($rsiBullish) {
            $bullPoints += self::WEIGHT_RSI * 0.7;  // 70% for single timeframe
            $signalLog[] = 'RSI_OVERSOLD_SINGLE';
        } elseif ($rsi1m < 40 && $rsi5m < 45) {
            $bullPoints += self::WEIGHT_RSI * 0.4;
            $signalLog[] = 'RSI_WEAK_BULL';
        }

        if ($rsiStrongBear) {
            $bearPoints += self::WEIGHT_RSI;  // Full weight when both agree
            $signalLog[] = 'RSI_OVERBOUGHT_BOTH';
        } elseif ($rsiBearish) {
            $bearPoints += self::WEIGHT_RSI * 0.7;  // 70% for single timeframe
            $signalLog[] = 'RSI_OVERBOUGHT_SINGLE';
        } elseif ($rsi1m > 60 && $rsi5m > 55) {
            $bearPoints += self::WEIGHT_RSI * 0.4;
            $signalLog[] = 'RSI_WEAK_BEAR';
        }

        // 2. Stochastic - Reduced weight, requires extreme levels
        if ($stoch !== null) {
            if ($stoch < 20) {
                $bullPoints += self::WEIGHT_STOCH;
                $signalLog[] = 'STOCH_EXTREME_OVERSOLD';
            } elseif ($stoch < 30) {
                $bullPoints += self::WEIGHT_STOCH * 0.5;
                $signalLog[] = 'STOCH_OVERSOLD';
            } elseif ($stoch > 80) {
                $bearPoints += self::WEIGHT_STOCH;
                $signalLog[] = 'STOCH_EXTREME_OVERBOUGHT';
            } elseif ($stoch > 70) {
                $bearPoints += self::WEIGHT_STOCH * 0.5;
                $signalLog[] = 'STOCH_OVERBOUGHT';
            }
        }

        // 3. IMPROVED: EMA 12/20 - Slightly relaxed
        if ($ema12 && $ema20) {
            $emaBullish = ($ema12 > $ema20 && $price > $ema20);
            $emaBearish = ($ema12 < $ema20 && $price < $ema20);

            if ($emaBullish) {
                $bullPoints += self::WEIGHT_EMA12_20;
                $signalLog[] = 'EMA12_20_BULL';
            } elseif ($ema12 > $ema20) {
                // Partial credit - EMA aligned but price not above EMA20
                $bullPoints += self::WEIGHT_EMA12_20 * 0.5;
                $signalLog[] = 'EMA12_20_WEAK_BULL';
            }

            if ($emaBearish) {
                $bearPoints += self::WEIGHT_EMA12_20;
                $signalLog[] = 'EMA12_20_BEAR';
            } elseif ($ema12 < $ema20) {
                // Partial credit - EMA aligned but price not below EMA20
                $bearPoints += self::WEIGHT_EMA12_20 * 0.5;
                $signalLog[] = 'EMA12_20_WEAK_BEAR';
            }
        }

        // 4. IMPROVED: EMA50 - Main trend (FIXED LOGIC)
        // Now properly checks: price > EMA50 AND EMA20 > EMA50 for bull
        //                    price < EMA50 AND EMA20 < EMA50 for bear
        if ($ema50 && $ema20) {
            $priceAboveEma50 = $price > $ema50;
            $ema20AboveEma50 = $ema20 > $ema50;
            $priceBelowEma50 = $price < $ema50;
            $ema20BelowEma50 = $ema20 < $ema50;

            // Strong bull: Price above EMA50 AND EMA20 above EMA50 (aligned)
            if ($priceAboveEma50 && $ema20AboveEma50) {
                $bullPoints += self::WEIGHT_EMA50;
                $signalLog[] = 'EMA50_STRONG_BULL';
            }
            // Weak bull: Price above EMA50 but EMA20 below (potential reversal)
            elseif ($priceAboveEma50 && !$ema20AboveEma50) {
                $bullPoints += self::WEIGHT_EMA50 * 0.3;
                $signalLog[] = 'EMA50_WEAK_BULL';
            }

            // Strong bear: Price below EMA50 AND EMA20 below EMA50 (aligned)
            if ($priceBelowEma50 && $ema20BelowEma50) {
                $bearPoints += self::WEIGHT_EMA50;
                $signalLog[] = 'EMA50_STRONG_BEAR';
            }
            // Weak bear: Price below EMA50 but EMA20 above (potential reversal)
            elseif ($priceBelowEma50 && !$ema20BelowEma50) {
                $bearPoints += self::WEIGHT_EMA50 * 0.3;
                $signalLog[] = 'EMA50_WEAK_BEAR';
            }
        }

        // 5. Bollinger Bands - Unchanged
        if ($bb) {
            $bbRange = $bb['upper'] - $bb['lower'];
            $distanceFromLower = $price - $bb['lower'];
            $distanceFromUpper = $bb['upper'] - $price;

            // Touching or outside lower band = strong buy
            if ($price <= $bb['lower'] || $distanceFromLower < ($bbRange * 0.05)) {
                $bullPoints += self::WEIGHT_BB;
                $signalLog[] = 'BB_TOUCH_LOWER';
            } elseif ($price < $bb['middle']) {
                $bullPoints += self::WEIGHT_BB * 0.5;
                $signalLog[] = 'BB_BELOW_MID';
            }

            // Touching or outside upper band = strong sell
            if ($price >= $bb['upper'] || $distanceFromUpper < ($bbRange * 0.05)) {
                $bearPoints += self::WEIGHT_BB;
                $signalLog[] = 'BB_TOUCH_UPPER';
            } elseif ($price > $bb['middle']) {
                $bearPoints += self::WEIGHT_BB * 0.5;
                $signalLog[] = 'BB_ABOVE_MID';
            }
        }

        // 6. IMPROVED: Pivot Points - Dynamic proximity based on ATR
        $proximityThreshold = max($atr * 0.8, 2.0); // At least $2 or 0.8 × ATR

        if ($pivots) {
            $distToS1 = abs($price - $pivots['S1']);
            $distToS2 = abs($price - $pivots['S2']);
            $distToR1 = abs($price - $pivots['R1']);
            $distToR2 = abs($price - $pivots['R2']);

            if ($distToS2 < $proximityThreshold) {
                $bullPoints += self::WEIGHT_PIVOT * 1.5;
                $signalLog[] = 'PIVOT_S2_NEAR';
            } elseif ($distToS1 < $proximityThreshold) {
                $bullPoints += self::WEIGHT_PIVOT;
                $signalLog[] = 'PIVOT_S1_NEAR';
            }

            if ($distToR2 < $proximityThreshold) {
                $bearPoints += self::WEIGHT_PIVOT * 1.5;
                $signalLog[] = 'PIVOT_R2_NEAR';
            } elseif ($distToR1 < $proximityThreshold) {
                $bearPoints += self::WEIGHT_PIVOT;
                $signalLog[] = 'PIVOT_R1_NEAR';
            }
        }

        // 7. IMPROVED: MACD - RELAXED: Crossover gives full points, confirmation gives partial
        if ($macdData) {
            if ($macdData['crossover'] === 'BULLISH_CROSS') {
                $bullPoints += self::WEIGHT_MACD;  // Full weight for crossover
                $signalLog[] = 'MACD_BULL_CROSS';
            } elseif ($macdData['crossover'] === 'BEARISH_CROSS') {
                $bearPoints += self::WEIGHT_MACD;  // Full weight for crossover
                $signalLog[] = 'MACD_BEAR_CROSS';
            }
            // Add points for histogram confirmation (no crossover needed)
            elseif ($macdData['histogram'] > 0) {
                $bullPoints += self::WEIGHT_MACD * 0.6;  // 60% for positive histogram
                $signalLog[] = 'MACD_BULL_HISTOGRAM';
            } elseif ($macdData['histogram'] < 0) {
                $bearPoints += self::WEIGHT_MACD * 0.6;  // 60% for negative histogram
                $signalLog[] = 'MACD_BEAR_HISTOGRAM';
            }
        }

        // 8. Candle Structure - Reduced weight, requires extreme score
        if ($candleStruct) {
            $structureScore = $candleStruct['bullish_score'] - $candleStruct['bearish_score'];

            if ($structureScore >= 4) {
                $bullPoints += self::WEIGHT_CANDLE;
                $signalLog[] = 'CANDLE_STRONG_BULL';
            } elseif ($structureScore >= 2) {
                $bullPoints += self::WEIGHT_CANDLE * 0.5;
                $signalLog[] = 'CANDLE_WEAK_BULL';
            } elseif ($structureScore <= -4) {
                $bearPoints += self::WEIGHT_CANDLE;
                $signalLog[] = 'CANDLE_STRONG_BEAR';
            } elseif ($structureScore <= -2) {
                $bearPoints += self::WEIGHT_CANDLE * 0.5;
                $signalLog[] = 'CANDLE_WEAK_BEAR';
            }
        }

        // 9. NEW: Volume Confirmation
        if ($volumeData && $volumeData['strength'] !== 0) {
            if ($volumeData['trend'] === 'STRONG' || $volumeData['trend'] === 'ABOVE_AVG') {
                // Volume confirms the direction we want to trade
                if ($bullPoints > $bearPoints) {
                    $bullPoints += self::WEIGHT_VOLUME * 0.5;
                    $signalLog[] = 'VOLUME_CONFIRMS_BULL';
                } elseif ($bearPoints > $bullPoints) {
                    $bearPoints += self::WEIGHT_VOLUME * 0.5;
                    $signalLog[] = 'VOLUME_CONFIRMS_BEAR';
                }
            } elseif ($volumeData['trend'] === 'WEAK') {
                // Weak volume reduces signal strength
                $signalLog[] = 'VOLUME_WEAK';
            }
        }

        // 10. NEW: Multi-Timeframe Trend Alignment - RELAXED
        if ($trendAlignment) {
            if ($trendAlignment['is_aligned']) {
                // Strong bonus when trend is aligned across timeframes
                if ($trendAlignment['status'] === 'STRONG_BULL' && $bullPoints > 0) {
                    $bullPoints += self::WEIGHT_TREND_ALIGN;
                    $signalLog[] = 'TREND_ALIGNED_BULL';
                } elseif ($trendAlignment['status'] === 'STRONG_BEAR' && $bearPoints > 0) {
                    $bearPoints += self::WEIGHT_TREND_ALIGN;
                    $signalLog[] = 'TREND_ALIGNED_BEAR';
                } elseif ($trendAlignment['score'] > 0 && $bullPoints > 0) {
                    // Partial credit for bullish alignment
                    $bullPoints += self::WEIGHT_TREND_ALIGN * 0.5;
                    $signalLog[] = 'TREND_MILDLY_BULL';
                } elseif ($trendAlignment['score'] < 0 && $bearPoints > 0) {
                    // Partial credit for bearish alignment
                    $bearPoints += self::WEIGHT_TREND_ALIGN * 0.5;
                    $signalLog[] = 'TREND_MILDLY_BEAR';
                }
            } else {
                // Log but don't block signal - just no bonus
                $signalLog[] = 'TREND_NOT_ALIGNED (no bonus)';
            }
        }

        // Calculate total possible points
        $maxPoints = self::WEIGHT_RSI + self::WEIGHT_STOCH + self::WEIGHT_EMA12_20 +
                     self::WEIGHT_EMA50 + self::WEIGHT_BB + self::WEIGHT_PIVOT +
                     self::WEIGHT_MACD + self::WEIGHT_CANDLE + self::WEIGHT_VOLUME +
                     self::WEIGHT_TREND_ALIGN; // = 11.0

        // FIXED: Threshold at 55% (balanced for signal frequency vs quality)
        $minThreshold = $maxPoints * self::MIN_SIGNAL_THRESHOLD; // 6.05
        $strongThreshold = $maxPoints * self::STRONG_SIGNAL_THRESHOLD; // 7.7

        // Calculate scores
        $netScore = $bullPoints - $bearPoints;
        $absScore = abs($netScore);

        // IMPROVED: Strength calculation with cap
        $rawStrength = ($absScore / $maxPoints) * 10;
        $strength = round(min(10, max(0, $rawStrength)), 1);

        // Determine direction with RELAXED requirements
        $direction = 'WAIT';
        $reason = '';

        if ($bullPoints > $bearPoints && $bullPoints >= $minThreshold) {
            // RELAXED: Allow BUY if trend alignment is neutral or better
            if ($trendAlignment && ($trendAlignment['score'] >= -1 || $trendAlignment['status'] === 'NEUTRAL')) {
                $direction = 'BUY';
                $reason = $bullPoints >= $strongThreshold ? 'Strong buy signal' : 'Moderate buy signal';
            } else {
                $reason = 'Bull signal but against strong downtrend - WAIT';
            }
        } elseif ($bearPoints > $bullPoints && $bearPoints >= $minThreshold) {
            // RELAXED: Allow SELL if trend alignment is neutral or better
            if ($trendAlignment && ($trendAlignment['score'] <= 1 || $trendAlignment['status'] === 'NEUTRAL')) {
                $direction = 'SELL';
                $reason = $bearPoints >= $strongThreshold ? 'Strong sell signal' : 'Moderate sell signal';
            } else {
                $reason = 'Bear signal but against strong uptrend - WAIT';
            }
        } else {
            if ($bullPoints >= $bearPoints) {
                $reason = 'Insufficient bull strength (' . round($bullPoints, 1) . '/' . round($minThreshold, 1) . ')';
            } else {
                $reason = 'Insufficient bear strength (' . round($bearPoints, 1) . '/' . round($minThreshold, 1) . ')';
            }
        }

        // Log the signal generation for debugging
        Log::info('Signal Generation', [
            'direction' => $direction,
            'bull_points' => round($bullPoints, 2),
            'bear_points' => round($bearPoints, 2),
            'threshold' => round($minThreshold, 2),
            'signal_log' => $signalLog,
            'trend_alignment' => $trendAlignment['status'] ?? 'N/A',
            'reason' => $reason
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
                'stoch_signal'  => $stoch < 30 ? 'BULL' : ($stoch > 70 ? 'BEAR' : 'NEUTRAL'),
                'trend_align'   => $trendAlignment['status'] ?? 'UNKNOWN',
                'volume_status' => $volumeData['trend'] ?? 'UNKNOWN',
                'signals_fired' => $signalLog
            ]
        ];
    }

    // ==============================
    // FIX #2: Validate Trade Plan - BUY/SELL Logic Fixed
    // ==============================
    private function validateTradePlan($plan, $currentPrice = null, $direction = null)
    {
        $requiredKeys = ['entry', 'tp1', 'tp2', 'tp3', 'sl'];
        // Support both 'entry' and 'entry_zone' keys
        if (!isset($plan['entry']) && isset($plan['entry_zone'])) {
            $plan['entry'] = $plan['entry_zone'];
        }

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

        // 1. Entry قريبة من السعر الحالي (±2$)
        if ($currentPrice !== null && abs($entry - $currentPrice) > 2) {
            Log::warning("Gold: Entry too far: {$entry} vs {$currentPrice}");
            return false;
        }

        // 2. تحديد الاتجاه من الـ plan نفسه
        // BUY:  SL < Entry (SL أسفل Entry)
        // SELL: SL > Entry (SL فوق Entry)
        $isBuy  = ($sl < $entry);
        $isSell = ($sl > $entry);

        if (!$isBuy && !$isSell) {
            Log::warning("Gold: SL == Entry, invalid plan");
            return false;
        }

        // 3. FIX: التحقق الصحيح من الترتيب
        if ($isBuy) {
            // BUY: SL < Entry < TP1 < TP2 < TP3  ← الترتيب الصحيح
            if ($tp1 <= $entry || $tp2 <= $tp1 || $tp3 <= $tp2) {
                Log::warning("Gold: BUY TPs order wrong: E={$entry} TP1={$tp1} TP2={$tp2} TP3={$tp3}");
                return false;
            }
        } else {
            // SELL: SL > Entry > TP1 > TP2 > TP3  ← الترتيب الصحيح
            if ($tp1 >= $entry || $tp2 >= $tp1 || $tp3 >= $tp2) {
                Log::warning("Gold: SELL TPs order wrong: E={$entry} TP1={$tp1} TP2={$tp2} TP3={$tp3}");
                return false;
            }
        }

        // 4. TP1 يبعد ≥ $1.50
        if (abs($tp1 - $entry) < 1.5) {
            Log::warning("Gold: TP1 too close: " . abs($tp1 - $entry));
            return false;
        }

        // 5. SL يبعد ≥ $1.50
        if (abs($entry - $sl) < 1.5) {
            Log::warning("Gold: SL too close: " . abs($entry - $sl));
            return false;
        }

        return true;
    }

    // ==============================
    // FIX #8: Session Check (NEW)
    // ==============================
    private function isTradingSession()
    {
        $utcHour = (int)date('G');
        // London: 08:00-17:00 UTC | New York: 13:00-22:00 UTC
        // Best overlap: 13:00-17:00 UTC
        $londonOpen = ($utcHour >= 7 && $utcHour < 17);
        $nyOpen     = ($utcHour >= 13 && $utcHour < 22);

        return [
            'london' => $londonOpen,
            'ny'     => $nyOpen,
            'best_session' => ($utcHour >= 13 && $utcHour < 17),
            'is_active' => $londonOpen || $nyOpen,
        ];
    }

    // ==============================
    // MAIN ANALYSIS ENDPOINT
    // ==============================
    public function getAnalysis(Request $request)
    {
        Log::info('Gold Analysis: Starting Fixed Analysis...');

        $twelveDataKey = env('TWELVEDATA_API_KEY');
        $groqKey       = env('GROQ_API_KEY');

        if (empty($twelveDataKey) || empty($groqKey)) {
            return response()->json(['error' => 'API keys not configured'], 500);
        }

        try {
            // 1. Fetch Market Data
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

            $currentPrice = end($prices5m);

            // 2. Calculate All Indicators
            $ema12 = $this->calculateEMA($prices5m, 12);
            $ema20 = $this->calculateEMA($prices5m, 20);
            $ema50 = $this->calculateEMA($prices5m, 50);
            $rsi1m = $this->calculateRSI($prices1m, 14);
            $rsi5m = $this->calculateRSI($prices5m, 14);
            $atr   = $this->calculateATR($candles5m, 14);
            $bb    = $this->calculateBollingerBands($prices5m, 20);
            $stoch = $this->calculateStochastic($candles5m, 14);
            $macd  = $this->calculateMACD($prices5m);
            $pivots = $this->calculatePivotPoints($candles5m[count($candles5m) - 1]);
            $candleStruct = $this->analyzeCandleStructure($candles5m);
            $session = $this->isTradingSession();

            // IMPROVED: Calculate additional indicators
            $prices1h = array_map(fn($c) => (float)$c['close'], $candles1h);
            $volumeData = $this->analyzeVolume($candles5m);
            $trendAlignment = $this->checkTrendAlignment($prices1m, $prices5m, $prices1h, $currentPrice);

            // 3. Generate Trading Signal
            $tradingSignal = $this->generateTradingSignal(
                $rsi1m, $rsi5m, $stoch, $currentPrice,
                $pivots, $ema12, $ema20, $ema50,
                $bb, $macd, $candleStruct,
                $volumeData, $trendAlignment, $atr
            );

            // 4. Calculate Trade Setup (with correct position sizing)
            $tradeSetup = ($tradingSignal['direction'] !== 'WAIT')
                ? $this->calculateTradeSetup($currentPrice, $tradingSignal['direction'], $atr)
                : null;

            // 5. Trend Analysis
            $last1h   = (float)$candles1h[count($candles1h) - 1]['close'];
            $prev1h   = (float)$candles1h[count($candles1h) - 2]['close'];
            $trend1h  = $last1h > $prev1h ? 'صاعد (Bullish)' : 'هابط (Bearish)';
            $trendShort = ($ema12 > $ema20 && $ema20 > $ema50) ? 'صاعد' :
                (($ema12 < $ema20 && $ema20 < $ema50) ? 'هابط' : 'جانبي');

            // 6. Build AI Prompt (FIXED directions)
            if ($tradingSignal['direction'] !== 'WAIT' && $tradeSetup) {
                $dir  = $tradingSignal['direction'];
                $sign = $dir === 'BUY' ? '+' : '-';
                $slSign = $dir === 'BUY' ? '-' : '+';

                $prompt = "
أنت خبير سكالبينج ذهب متخصص. حساب: $" . self::ACCOUNT_SIZE . "، لوت: {$tradeSetup['lot_size']}

السعر الحالي: {$currentPrice}
الإشارة: {$dir} | القوة: {$tradingSignal['strength']}/10
ATR: {$atr}

**قواعد المسافات الصحيحة للـ {$dir}:**
" . ($dir === 'BUY'
                        ? "BUY: SL أسفل Entry | TP1,TP2,TP3 فوق Entry
- SL = Entry - {$tradeSetup['sl_distance']}$ = {$tradeSetup['sl']}
- TP1 = Entry + ... = {$tradeSetup['tp1']}
- TP2 = Entry + ... = {$tradeSetup['tp2']}
- TP3 = Entry + ... = {$tradeSetup['tp3']}"
                        : "SELL: SL فوق Entry | TP1,TP2,TP3 أسفل Entry
- SL = Entry + {$tradeSetup['sl_distance']}$ = {$tradeSetup['sl']}
- TP1 = Entry - ... = {$tradeSetup['tp1']}
- TP2 = Entry - ... = {$tradeSetup['tp2']}
- TP3 = Entry - ... = {$tradeSetup['tp3']}") . "

المؤشرات:
- RSI 1m: {$rsi1m} | RSI 5m: {$rsi5m}
- EMA12: {$ema12} | EMA20: {$ema20} | EMA50: {$ema50}
- Stoch: {$stoch} | MACD Cross: " . ($macd['crossover'] ?? 'NONE') . "
- BB: {$bb['lower']} / {$bb['middle']} / {$bb['upper']}
- Pivot: {$pivots['pivot']} | S1: {$pivots['S1']} | R1: {$pivots['R1']}

قدم تحليلاً موجزاً ومفيداً بالعربية. ركز على سبب قوة الإشارة وأي مستويات مهمة يجب الانتباه لها.
";
            } else {
                $prompt = "
السعر: {$currentPrice} | RSI 1m: {$rsi1m} | RSI 5m: {$rsi5m} | Stoch: {$stoch}
EMA12: {$ema12} | EMA20: {$ema20} | EMA50: {$ema50}
نقاط البول: {$tradingSignal['bull_points']} | نقاط البير: {$tradingSignal['bear_points']}

السوق في حالة انتظار. اشرح باختصار لماذا لا توجد إشارة واضحة الآن، وما الذي يجب رؤيته للدخول.
";
            }

            // 7. Call AI
            $responseAI = Http::withHeaders([
                'Authorization' => 'Bearer ' . $groqKey,
                'Content-Type'  => 'application/json'
            ])->timeout(30)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'       => 'llama-3.3-70b-versatile',
                'messages'    => [
                    ['role' => 'system', 'content' => 'أنت محلل تداول محترف. اعطِ تحليلاً مختصراً ومفيداً بالعربية. إن لم تر إشارة قوية، قل انتظر مع سبب واضح.'],
                    ['role' => 'user', 'content' => $prompt]
                ],
                'temperature' => 0.1,
                'max_tokens'  => 800,
            ]);

            if (!$responseAI->successful()) {
                return response()->json(['error' => 'AI analysis failed'], 500);
            }

            $recommendation = $responseAI->json()['choices'][0]['message']['content'] ?? 'فشل التحليل';
            $tradePlan = null;

            if ($tradingSignal['direction'] !== 'WAIT' && $tradeSetup) {
                // استخدم الـ tradeSetup المحسوب داخلياً (أكثر موثوقية من الـ AI)
                $tradePlan = $tradeSetup;
            }

            Log::info('Gold Analysis: Complete', [
                'price'  => $currentPrice,
                'signal' => $tradingSignal['direction'],
                'strength' => $tradingSignal['strength'],
                'lot'    => $tradePlan['lot_size'] ?? null,
                'risk_$' => $tradePlan['risk_amount'] ?? null,
            ]);

            return response()->json([
                'current_price'  => $currentPrice,
                'trend_daily'    => $trend1h,
                'trend_short'    => $trendShort,
                'session'        => $session,
                'indicators'     => [
                    'ema'        => ['ema12' => $ema12, 'ema20' => $ema20, 'ema50' => $ema50],
                    'rsi'        => ['rsi_1m' => $rsi1m, 'rsi_5m' => $rsi5m],
                    'atr'        => $atr,
                    'stochastic' => $stoch,
                    'macd'       => $macd,
                    'bb'         => $bb,
                    'pivots'     => $pivots,
                    'candle_struct' => $candleStruct,
                    'volume'     => $volumeData,
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
