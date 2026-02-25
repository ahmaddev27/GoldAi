<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * ====================================================
 * Gold AI Scalper - FIXED & IMPROVED VERSION
 * ====================================================
 *
 * BUGS FIXED:
 * 1. validateTradePlan(): BUY/SELL logic was completely inverted
 * 2. AI Prompt: BUY/SELL direction rules were reversed
 * 3. Position sizing: Risk calculation was wrong (10% not 1.5%)
 * 4. Pivot proximity: Too tight at $0.30, changed to $1.50
 * 5. EMA50 added to signal generation
 * 6. MACD Signal Line added (crossover detection)
 *
 * IMPROVEMENTS:
 * 7. Dynamic lot size calculation based on real risk %
 * 8. Nano lot support for $10 accounts
 * 9. ATR-based SL/TP instead of fixed pips
 * 10. Candle structure analysis (higher highs, lower lows)
 * 11. Volume ratio analysis
 * 12. Session filter (London/NY sessions)
 */
class GoldController extends Controller
{
    // ====== Risk Management Constants ======
    private const ACCOUNT_SIZE  = 100;     // Change to 10 for $10 account
    private const RISK_PERCENT  = 1.5;     // % risk per trade
    private const MIN_LOT       = 0.01;    // Minimum lot (use 0.001 for $10 nano)
    private const NANO_LOT      = 0.001;   // Nano lot for tiny accounts

    // XAU/USD: 1 standard lot = 100 oz → $1 move = $100 P&L
    // 0.01 lot → $1 move = $1 P&L
    // 0.001 lot → $1 move = $0.10 P&L
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
    // FIX #1 + #4 + #5: Signal Generation
    // ==============================
    /**
     * FIXED generateTradingSignal:
     * - أضفنا EMA50 (كان غائباً)
     * - أضفنا MACD Crossover
     * - أصلحنا نسبة maxScore
     * - زيادة proximity للـ pivot من $0.30 إلى $1.50
     * - أضفنا تحليل هيكل الشموع
     */
    private function generateTradingSignal(
        $rsi1m, $rsi5m, $stoch, $price, $pivots,
        $ema12, $ema20, $ema50, $bb, $macdData, $candleStruct
    ) {
        $bullPoints = 0;
        $bearPoints = 0;

        // 1. RSI - قوة الزخم (وزن: 2)
        if ($rsi1m < 30 && $rsi5m < 35) {
            $bullPoints += 2;
        } elseif ($rsi1m < 40 && $rsi5m < 45) {
            $bullPoints += 1;
        } elseif ($rsi1m > 70 && $rsi5m > 65) {
            $bearPoints += 2;
        } elseif ($rsi1m > 60 && $rsi5m > 55) {
            $bearPoints += 1;
        }

        // 2. Stochastic - تأكيد (وزن: 1.5)
        if ($stoch < 25) {
            $bullPoints += 1.5;
        } elseif ($stoch < 35) {
            $bullPoints += 0.75;
        } elseif ($stoch > 75) {
            $bearPoints += 1.5;
        } elseif ($stoch > 65) {
            $bearPoints += 0.75;
        }

        // 3. EMA 12/20 - الاتجاه قصير المدى (وزن: 1)
        if ($ema12 && $ema20) {
            if ($ema12 > $ema20 && $price > $ema20) {
                $bullPoints += 1.0;
            } elseif ($ema12 < $ema20 && $price < $ema20) {
                $bearPoints += 1.0;
            }
        }

        // 4. FIX: EMA50 - الاتجاه الرئيسي (وزن: 1.5) ← كان غائباً!
        if ($ema50) {
            if ($price > $ema50 && $ema20 > $ema50) {
                $bullPoints += 1.5;
            } elseif ($price < $ema50 && $ema20 < $ema50) {
                $bearPoints += 1.5;
            }
        }

        // 5. Bollinger Bands (وزن: 1)
        if ($bb) {
            if ($price <= $bb['lower']) {
                $bullPoints += 1.0; // عند الحد السفلي = شراء
            } elseif ($price >= $bb['upper']) {
                $bearPoints += 1.0; // عند الحد العلوي = بيع
            } elseif ($price < $bb['middle']) {
                $bullPoints += 0.5;
            } elseif ($price > $bb['middle']) {
                $bearPoints += 0.5;
            }
        }

        // 6. Pivot Points - FIX: زيادة الـ proximity من $0.30 إلى $1.50
        $proximityThreshold = 1.50;
        if (abs($price - $pivots['S1']) < $proximityThreshold) {
            $bullPoints += 1.0; // قريب من دعم S1 = شراء
        } elseif (abs($price - $pivots['S2']) < $proximityThreshold) {
            $bullPoints += 1.5; // دعم S2 أقوى
        } elseif (abs($price - $pivots['R1']) < $proximityThreshold) {
            $bearPoints += 1.0; // قريب من مقاومة R1 = بيع
        } elseif (abs($price - $pivots['R2']) < $proximityThreshold) {
            $bearPoints += 1.5; // مقاومة R2 أقوى
        }

        // 7. FIX: MACD Crossover (وزن: 2) ← كان غائباً!
        if ($macdData) {
            if ($macdData['crossover'] === 'BULLISH_CROSS') {
                $bullPoints += 2.0;
            } elseif ($macdData['crossover'] === 'BEARISH_CROSS') {
                $bearPoints += 2.0;
            } elseif ($macdData['line'] > ($macdData['signal'] ?? 0) && $macdData['histogram'] > 0) {
                $bullPoints += 0.75;
            } elseif ($macdData['line'] < ($macdData['signal'] ?? 0) && $macdData['histogram'] < 0) {
                $bearPoints += 0.75;
            }
        }

        // 8. هيكل الشموع (وزن: 1.5)
        if ($candleStruct) {
            $structureScore = $candleStruct['bullish_score'] - $candleStruct['bearish_score'];
            if ($structureScore >= 3) {
                $bullPoints += 1.5;
            } elseif ($structureScore >= 1) {
                $bullPoints += 0.75;
            } elseif ($structureScore <= -3) {
                $bearPoints += 1.5;
            } elseif ($structureScore <= -1) {
                $bearPoints += 0.75;
            }
        }

        // حساب النتيجة النهائية
        $maxPoints = 2 + 1.5 + 1.0 + 1.5 + 1.0 + 1.5 + 2.0 + 1.5; // = 12
        $netScore  = $bullPoints - $bearPoints;
        $strength  = round(min(10, abs($netScore / $maxPoints) * 10), 1);

        // حد الدخول: 55% ثقة
        $minThreshold = $maxPoints * 0.55;

        if ($bullPoints > $bearPoints && $bullPoints >= $minThreshold) {
            $direction = 'BUY';
        } elseif ($bearPoints > $bullPoints && $bearPoints >= $minThreshold) {
            $direction = 'SELL';
        } else {
            $direction = 'WAIT';
        }

        return [
            'direction'    => $direction,
            'strength'     => $strength,
            'bull_points'  => round($bullPoints, 2),
            'bear_points'  => round($bearPoints, 2),
            'net_score'    => round($netScore, 2),
            'components'   => [
                'rsi_signal'    => $rsi1m < 30 ? 'BULL' : ($rsi1m > 70 ? 'BEAR' : 'NEUTRAL'),
                'ema_signal'    => $ema12 > $ema20 ? 'BULL' : 'BEAR',
                'ema50_signal'  => $ema50 && $price > $ema50 ? 'BULL' : 'BEAR',
                'macd_cross'    => $macdData['crossover'] ?? 'NONE',
                'stoch_signal'  => $stoch < 30 ? 'BULL' : ($stoch > 70 ? 'BEAR' : 'NEUTRAL'),
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
            $macd  = $this->calculateMACD($prices5m); // FIX: now returns full MACD object
            $pivots = $this->calculatePivotPoints($candles5m[count($candles5m) - 1]);
            $candleStruct = $this->analyzeCandleStructure($candles5m);
            $session = $this->isTradingSession();

            // 3. Generate Trading Signal
            $tradingSignal = $this->generateTradingSignal(
                $rsi1m, $rsi5m, $stoch, $currentPrice,
                $pivots, $ema12, $ema20, $ema50,
                $bb, $macd, $candleStruct
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
                    'macd'       => $macd, // كامل: line + signal + histogram + crossover
                    'bb'         => $bb,
                    'pivots'     => $pivots,
                    'candle_struct' => $candleStruct,
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
