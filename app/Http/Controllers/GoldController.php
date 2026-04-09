<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

/**
 * ╔══════════════════════════════════════════════════════════╗
 * ║  Gold & BTC Scalper v7                                   ║
 * ║  الإصلاح الجذري: إشارات واقعية تعمل فعلاً               ║
 * ╚══════════════════════════════════════════════════════════╝
 */
class GoldController extends Controller
{
    private const SYMBOLS = [
        'gold' => [
            'symbol'       => 'XAU/USD',
            'account'      => 100,
            'risk_pct'     => 1.5,
            'oz_per_lot'   => 100,
            'min_lot'      => 0.01,
            'spread'       => 0.30,
            'atr_min'      => 0.5,
            'sl_min'       => 1.0,
            'sl_max'       => 12.0,
            'tp1_mult'     => 1.0,
            'tp2_mult'     => 2.0,
            'tp3_mult'     => 3.0,
            'sl_mult'      => 1.1,
            'label'        => 'الذهب XAU/USD',
        ],
        'btc' => [
            'symbol'       => 'BTC/USD',
            'account'      => 100,
            'risk_pct'     => 1.5,
            'oz_per_lot'   => 1,      // BTC: 1 lot = 1 BTC
            'min_lot'      => 0.001,
            'spread'       => 15.0,
            'atr_min'      => 50.0,
            'sl_min'       => 50.0,
            'sl_max'       => 800.0,
            'tp1_mult'     => 1.0,
            'tp2_mult'     => 2.0,
            'tp3_mult'     => 3.2,
            'sl_mult'      => 1.1,
            'label'        => 'بيتكوين BTC/USD',
        ],
    ];

    public function landing()
    {
        return view('landing');
    }

    // ──────────────────────────────────────────────────────────────
    // السعر اللحظي — يُجلب عند الطلب فقط (Cache 8s)
    // ──────────────────────────────────────────────────────────────
    public function getLivePrice(Request $request)
    {
        $asset = $request->get('asset', 'gold');
        $cfg   = self::SYMBOLS[$asset] ?? self::SYMBOLS['gold'];
        $key   = env('TWELVEDATA_API_KEY');

        if (!$key) return response()->json(['error' => 'API key missing'], 500);

        $cacheKey = "price_{$asset}_v7";
        $data = Cache::remember($cacheKey, 8, function () use ($key, $cfg) {
            try {
                $r = Http::timeout(6)->get(
                    "https://api.twelvedata.com/price?symbol={$cfg['symbol']}&apikey={$key}"
                )->json();
                if (isset($r['price']) && (float)$r['price'] > 0) {
                    return ['price' => round((float)$r['price'], 2), 'source' => 'live', 'ts' => now()->format('H:i:s')];
                }
            } catch (\Exception $e) {}

            try {
                $q = Http::timeout(6)->get(
                    "https://api.twelvedata.com/quote?symbol={$cfg['symbol']}&apikey={$key}"
                )->json();
                if (isset($q['close'])) {
                    return [
                        'price'      => round((float)$q['close'], 2),
                        'source'     => 'quote',
                        'ts'         => now()->format('H:i:s'),
                        'change'     => round((float)($q['change'] ?? 0), 2),
                        'change_pct' => round((float)($q['percent_change'] ?? 0), 2),
                    ];
                }
            } catch (\Exception $e) {}
            return null;
        });

        if (!$data) return response()->json(['error' => 'unavailable'], 503);
        return response()->json($data)->header('Cache-Control', 'no-store');
    }

    // ──────────────────────────────────────────────────────────────
    // INDICATORS
    // ──────────────────────────────────────────────────────────────

    private function ema(array $p, int $period): ?float
    {
        if (count($p) < $period) return null;
        $k = 2.0 / ($period + 1);
        $v = array_sum(array_slice($p, 0, $period)) / $period;
        for ($i = $period; $i < count($p); $i++) $v = $p[$i] * $k + $v * (1 - $k);
        return round($v, 4);
    }

    private function rsi(array $p, int $period = 14): float
    {
        $n = count($p);
        if ($n < $period + 1) return 50.0;
        $g = $l = [];
        for ($i = 1; $i < $n; $i++) {
            $d = $p[$i] - $p[$i - 1];
            $g[] = $d > 0 ? $d : 0;
            $l[] = $d < 0 ? -$d : 0;
        }
        $ag = array_sum(array_slice($g, 0, $period)) / $period;
        $al = array_sum(array_slice($l, 0, $period)) / $period;
        for ($i = $period; $i < count($g); $i++) {
            $ag = ($ag * ($period - 1) + $g[$i]) / $period;
            $al = ($al * ($period - 1) + $l[$i]) / $period;
        }
        if ($al == 0) return 100.0;
        return round(100 - 100 / (1 + $ag / $al), 2);
    }

    private function atr(array $candles, int $period = 14): float
    {
        if (count($candles) < $period + 1) return 1.0;
        $trs = [];
        for ($i = 1; $i < count($candles); $i++) {
            $h = (float)$candles[$i]['high'];
            $l = (float)$candles[$i]['low'];
            $c = (float)$candles[$i - 1]['close'];
            $trs[] = max($h - $l, abs($h - $c), abs($l - $c));
        }
        $a = array_sum(array_slice($trs, 0, $period)) / $period;
        for ($i = $period; $i < count($trs); $i++)
            $a = ($a * ($period - 1) + $trs[$i]) / $period;
        return round($a, 4);
    }

    private function bb(array $p, int $period = 20): ?array
    {
        if (count($p) < $period) return null;
        $slice = array_slice($p, -$period);
        $sma   = array_sum($slice) / $period;
        $std   = sqrt(array_sum(array_map(fn($x) => ($x - $sma) ** 2, $slice)) / $period);
        $upper = $sma + 2 * $std;
        $lower = $sma - 2 * $std;
        $last  = end($p);
        $pctB  = ($upper != $lower) ? round(($last - $lower) / ($upper - $lower) * 100, 1) : 50.0;
        return [
            'upper'  => round($upper, 2),
            'middle' => round($sma,   2),
            'lower'  => round($lower, 2),
            'width'  => round($upper - $lower, 4),
            'pct_b'  => $pctB,
        ];
    }

    private function stoch(array $candles, int $period = 14): float
    {
        if (count($candles) < $period) return 50.0;
        $slice = array_slice($candles, -$period);
        $hi    = max(array_map(fn($c) => (float)$c['high'], $slice));
        $lo    = min(array_map(fn($c) => (float)$c['low'],  $slice));
        $cl    = (float)$candles[count($candles) - 1]['close'];
        return $hi == $lo ? 50.0 : round(($cl - $lo) / ($hi - $lo) * 100, 2);
    }

    private function macd(array $p): ?array
    {
        if (count($p) < 35) return null;
        $line = [];
        for ($i = 26; $i <= count($p); $i++) {
            $e12 = $this->ema(array_slice($p, 0, $i), 12);
            $e26 = $this->ema(array_slice($p, 0, $i), 26);
            if ($e12 && $e26) $line[] = round($e12 - $e26, 4);
        }
        if (count($line) < 9) return null;
        $mv   = end($line);
        $sig  = $this->ema($line, 9);
        $hist = round($mv - ($sig ?? 0), 4);

        $cross = null;
        if (count($line) >= 2) {
            $prev    = $line[count($line) - 2];
            $prevSig = $this->ema(array_slice($line, 0, -1), 9) ?? 0;
            if ($prev < $prevSig && $mv > ($sig ?? 0)) $cross = 'BULL';
            if ($prev > $prevSig && $mv < ($sig ?? 0)) $cross = 'BEAR';
        }

        // MACD histogram reversal (الأهم للسكالبينج)
        $histRev = null;
        if (count($line) >= 3) {
            $h1 = $line[count($line) - 2] - ($this->ema(array_slice($line, 0, -1), 9) ?? 0);
            $h2 = $line[count($line) - 3] - ($this->ema(array_slice($line, 0, -2), 9) ?? 0);
            if ($h2 < 0 && $h1 < 0 && $hist > $h1) $histRev = 'BULL_REV'; // histogram يتحسن من سلبي
            if ($h2 > 0 && $h1 > 0 && $hist < $h1) $histRev = 'BEAR_REV'; // histogram يتراجع من إيجابي
        }

        return [
            'line'     => round($mv, 4),
            'signal'   => $sig ? round($sig, 4) : null,
            'hist'     => $hist,
            'cross'    => $cross,
            'hist_rev' => $histRev,
            'bull'     => $hist > 0 || $cross === 'BULL' || $histRev === 'BULL_REV',
            'bear'     => $hist < 0 || $cross === 'BEAR' || $histRev === 'BEAR_REV',
        ];
    }

    private function pivots(array $c): array
    {
        $h = (float)$c['high']; $l = (float)$c['low']; $cl = (float)$c['close'];
        $p = ($h + $l + $cl) / 3;
        return [
            'P'  => round($p, 2),
            'R1' => round(2 * $p - $l, 2),
            'R2' => round($p + ($h - $l), 2),
            'S1' => round(2 * $p - $h, 2),
            'S2' => round($p - ($h - $l), 2),
        ];
    }

    private function candleScore(array $candles): array
    {
        if (count($candles) < 2) return ['bull' => 0, 'bear' => 0, 'pattern' => 'NONE'];

        $c  = end($candles);
        $p  = $candles[count($candles) - 2];
        $o  = (float)$c['open']; $cl = (float)$c['close'];
        $h  = (float)$c['high']; $l  = (float)$c['low'];
        $po = (float)$p['open']; $pc = (float)$p['close'];

        $body  = abs($cl - $o);
        $range = max($h - $l, 0.0001);
        $upper = $h - max($o, $cl);
        $lower = min($o, $cl) - $l;
        $bull  = $cl > $o;

        // Hammer
        if ($lower > $body * 2 && $lower > $upper * 1.5 && $body > 0)
            return ['bull' => 2, 'bear' => 0, 'pattern' => 'HAMMER'];
        // Shooting Star
        if ($upper > $body * 2 && $upper > $lower * 1.5 && $body > 0)
            return ['bull' => 0, 'bear' => 2, 'pattern' => 'SHOOTING_STAR'];
        // Bullish Engulf
        if ($bull && $pc < $po && $cl > $po && $o < $pc)
            return ['bull' => 2, 'bear' => 0, 'pattern' => 'BULL_ENGULF'];
        // Bearish Engulf
        if (!$bull && $pc > $po && $cl < $po && $o > $pc)
            return ['bull' => 0, 'bear' => 2, 'pattern' => 'BEAR_ENGULF'];
        // Strong body
        if ($bull  && $body / $range > 0.6) return ['bull' => 1, 'bear' => 0, 'pattern' => 'STRONG_BULL'];
        if (!$bull && $body / $range > 0.6) return ['bull' => 0, 'bear' => 1, 'pattern' => 'STRONG_BEAR'];

        return ['bull' => ($bull ? 0.5 : 0), 'bear' => ($bull ? 0 : 0.5), 'pattern' => 'NEUTRAL'];
    }

    private function swingLevels(array $candles, int $n = 15): array
    {
        $slice = array_slice($candles, -$n);
        return [
            'high' => round(max(array_map(fn($c) => (float)$c['high'], $slice)), 2),
            'low'  => round(min(array_map(fn($c) => (float)$c['low'],  $slice)), 2),
        ];
    }

    private function trendBias(array $p1h): string
    {
        $e20 = $this->ema($p1h, 20);
        $e50 = $this->ema($p1h, 50);
        $last = end($p1h);
        $prev = $p1h[count($p1h) - 2] ?? $last;

        $bull = 0;
        if ($e20 && $last > $e20) $bull++;
        if ($e50 && $last > $e50) $bull++;
        if ($e20 && $e50 && $e20 > $e50) $bull++;
        if ($last > $prev) $bull++;

        if ($bull >= 3) return 'BULL';
        if ($bull <= 1) return 'BEAR';
        return 'NEUTRAL';
    }

    private function session(): array
    {
        $h = (int)date('G');
        $london  = ($h >= 7  && $h < 17);
        $ny      = ($h >= 13 && $h < 22);
        $overlap = ($h >= 13 && $h < 17);
        $asian   = ($h >= 0  && $h < 7);
        return [
            'london'   => $london, 'ny' => $ny,
            'overlap'  => $overlap, 'asian' => $asian,
            'active'   => $london || $ny,
            'label'    => $overlap ? 'London+NY 🔥' :
                ($london ? 'London 🇬🇧' :
                    ($ny ? 'New York 🇺🇸' :
                        ($asian ? 'Asian 🌙' : 'مغلق'))),
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  نظام الإشارة المُصلح — يعمل بشكل فعلي
    //  المشكلة القديمة: شروط مستحيلة تحجب كل شيء
    //  الحل: نقاط مرجّحة بشروط واضحة وقابلة للتحقق
    // ══════════════════════════════════════════════════════════════
    private function generateSignal(
        float  $price,
        float  $rsi1m,
        float  $rsi5m,
        float  $stoch,
        ?array $bb,
        ?array $macd,
        ?array $piv,
        ?float $e12,
        ?float $e20,
        ?float $e50,
        array  $candle,
        float  $atr,
        string $trend1h,
        array  $swing,
        array  $session,
        array  $cfg
    ): array {

        // ──────────────────────────────────────────────────────
        // نقاط الشراء والبيع (كل مؤشر يُعطي نقاطه بشكل مستقل)
        // ──────────────────────────────────────────────────────
        $bull = 0.0;
        $bear = 0.0;
        $tags = [];

        // ① RSI 1m — الأثقل وزناً
        if      ($rsi1m <= 20) { $bull += 3.0; $tags[] = 'RSI1m_EXTREME_LOW'; }
        elseif  ($rsi1m <= 30) { $bull += 2.0; $tags[] = 'RSI1m_OVERSOLD'; }
        elseif  ($rsi1m <= 40) { $bull += 1.0; $tags[] = 'RSI1m_LOW'; }
        elseif  ($rsi1m <= 50) { $bull += 0.4; }
        elseif  ($rsi1m <= 60) { $bear += 0.4; }
        elseif  ($rsi1m <= 70) { $bear += 1.0; $tags[] = 'RSI1m_HIGH'; }
        elseif  ($rsi1m <= 80) { $bear += 2.0; $tags[] = 'RSI1m_OVERBOUGHT'; }
        else                   { $bear += 3.0; $tags[] = 'RSI1m_EXTREME_HIGH'; }

        // ② RSI 5m — وزن متوسط
        if      ($rsi5m <= 25) { $bull += 2.5; $tags[] = 'RSI5m_EXTREME_LOW'; }
        elseif  ($rsi5m <= 35) { $bull += 1.5; $tags[] = 'RSI5m_OVERSOLD'; }
        elseif  ($rsi5m <= 45) { $bull += 0.7; $tags[] = 'RSI5m_LOW'; }
        elseif  ($rsi5m <= 55) { /* neutral */ }
        elseif  ($rsi5m <= 65) { $bear += 0.7; $tags[] = 'RSI5m_HIGH'; }
        elseif  ($rsi5m <= 75) { $bear += 1.5; $tags[] = 'RSI5m_OVERBOUGHT'; }
        else                   { $bear += 2.5; $tags[] = 'RSI5m_EXTREME_HIGH'; }

        // ③ Stochastic
        if      ($stoch <= 15) { $bull += 2.0; $tags[] = 'STOCH_EXTREME_LOW'; }
        elseif  ($stoch <= 25) { $bull += 1.3; $tags[] = 'STOCH_OVERSOLD'; }
        elseif  ($stoch <= 40) { $bull += 0.5; $tags[] = 'STOCH_LOW'; }
        elseif  ($stoch >= 85) { $bear += 2.0; $tags[] = 'STOCH_EXTREME_HIGH'; }
        elseif  ($stoch >= 75) { $bear += 1.3; $tags[] = 'STOCH_OVERBOUGHT'; }
        elseif  ($stoch >= 60) { $bear += 0.5; $tags[] = 'STOCH_HIGH'; }

        // ④ EMA Alignment
        if ($e12 && $e20 && $e50) {
            if ($e12 > $e20 && $e20 > $e50 && $price > $e20) {
                $bull += 2.0; $tags[] = 'EMA_FULL_BULL';
            } elseif ($e12 > $e20 && $price > $e20) {
                $bull += 1.0; $tags[] = 'EMA_BULL';
            } elseif ($e12 > $e20) {
                $bull += 0.5; $tags[] = 'EMA_WEAK_BULL';
            } elseif ($e12 < $e20 && $e20 < $e50 && $price < $e20) {
                $bear += 2.0; $tags[] = 'EMA_FULL_BEAR';
            } elseif ($e12 < $e20 && $price < $e20) {
                $bear += 1.0; $tags[] = 'EMA_BEAR';
            } elseif ($e12 < $e20) {
                $bear += 0.5; $tags[] = 'EMA_WEAK_BEAR';
            }
        } elseif ($e12 && $e20) {
            if ($e12 > $e20) { $bull += 0.8; $tags[] = 'EMA12>20_BULL'; }
            else              { $bear += 0.8; $tags[] = 'EMA12<20_BEAR'; }
        }

        // ⑤ Bollinger Bands
        if ($bb) {
            $pct = $bb['pct_b'];
            if      ($pct <= 0)    { $bull += 2.0; $tags[] = 'BB_BELOW_LOWER'; }
            elseif  ($pct <= 15)   { $bull += 1.5; $tags[] = 'BB_TOUCH_LOWER'; }
            elseif  ($pct <= 30)   { $bull += 0.7; $tags[] = 'BB_NEAR_LOWER'; }
            elseif  ($pct >= 100)  { $bear += 2.0; $tags[] = 'BB_ABOVE_UPPER'; }
            elseif  ($pct >= 85)   { $bear += 1.5; $tags[] = 'BB_TOUCH_UPPER'; }
            elseif  ($pct >= 70)   { $bear += 0.7; $tags[] = 'BB_NEAR_UPPER'; }
        }

        // ⑥ MACD
        if ($macd) {
            if ($macd['cross'] === 'BULL')        { $bull += 2.0; $tags[] = 'MACD_BULL_CROSS'; }
            elseif ($macd['hist_rev'] === 'BULL_REV') { $bull += 1.5; $tags[] = 'MACD_BULL_REV'; }
            elseif ($macd['hist'] > 0.03)         { $bull += 0.8; $tags[] = 'MACD_BULL_HIST'; }
            elseif ($macd['hist'] > 0)            { $bull += 0.3; }

            if ($macd['cross'] === 'BEAR')        { $bear += 2.0; $tags[] = 'MACD_BEAR_CROSS'; }
            elseif ($macd['hist_rev'] === 'BEAR_REV') { $bear += 1.5; $tags[] = 'MACD_BEAR_REV'; }
            elseif ($macd['hist'] < -0.03)        { $bear += 0.8; $tags[] = 'MACD_BEAR_HIST'; }
            elseif ($macd['hist'] < 0)            { $bear += 0.3; }
        }

        // ⑦ Pivot Levels
        if ($piv) {
            $prox = max($atr * 0.8, $cfg['sl_min']);
            if (abs($price - $piv['S2']) < $prox) { $bull += 1.5; $tags[] = 'AT_S2'; }
            elseif (abs($price - $piv['S1']) < $prox) { $bull += 1.0; $tags[] = 'AT_S1'; }
            if (abs($price - $piv['R2']) < $prox) { $bear += 1.5; $tags[] = 'AT_R2'; }
            elseif (abs($price - $piv['R1']) < $prox) { $bear += 1.0; $tags[] = 'AT_R1'; }
        }

        // ⑧ Candle Pattern
        $bull += $candle['bull'] * 0.8;
        $bear += $candle['bear'] * 0.8;
        if ($candle['pattern'] !== 'NEUTRAL' && $candle['pattern'] !== 'NONE') {
            $tags[] = 'CANDLE_' . $candle['pattern'];
        }

        // ⑨ Trend 1h (bonus/penalty مهم)
        if ($trend1h === 'BULL') {
            $bull += 1.5; $tags[] = '1H_BULL';
            // عقوبة البيع ضد الاتجاه
            $bear = max(0, $bear - 1.0);
        } elseif ($trend1h === 'BEAR') {
            $bear += 1.5; $tags[] = '1H_BEAR';
            $bull = max(0, $bull - 1.0);
        }

        // ⑩ Session bonus (الأفضل: overlap)
        if ($session['overlap']) {
            if ($bull > $bear) $bull += 0.5;
            else               $bear += 0.5;
            $tags[] = 'OVERLAP_SESSION';
        } elseif ($session['active']) {
            if ($bull > $bear) $bull += 0.2;
            else               $bear += 0.2;
        }

        // ⑪ تحذيرات (تخفض النقاط)
        $warnings = [];
        if ($session['asian']) {
            $warnings[] = '⚠️ الجلسة الآسيوية — تقلّب منخفض';
            $bull *= 0.7; $bear *= 0.7; // تخفيض لكن لا حجب كامل
        }
        if ($atr < $cfg['atr_min']) {
            $warnings[] = "⚠️ ATR منخفض ({$atr}) — سوق راكد";
            $bull *= 0.8; $bear *= 0.8;
        }

        // ──────────────────────────────────────────────────────
        // العتبات — عدّلناها لتكون واقعية
        // الحد الأدنى: 4.0 نقطة (يمكن تحقيقه بـ RSI+EMA+MACD)
        // الحد القوي: 7.0 نقطة
        // ──────────────────────────────────────────────────────
        $threshold      = 4.0;
        $strongThreshold= 7.0;

        $direction = 'WAIT';
        $reason    = '';
        $strength  = 0;

        // الاتجاه الكبير لا يُحجب تماماً لكن يُشترط أن يكون النصر واضحاً ضده
        $againstTrend = ($trend1h === 'BULL' && $bear > $bull) ||
            ($trend1h === 'BEAR' && $bull > $bear);

        // إذا كان ضد الاتجاه: نرفع العتبة
        $effectiveThreshold = $againstTrend ? $threshold + 2.0 : $threshold;

        $dominant = max($bull, $bear);
        $strength = round(min(10, ($dominant / 12.0) * 10), 1);

        if ($bull >= $effectiveThreshold && $bull > $bear * 1.1) {
            $direction = 'BUY';
            $reason = $bull >= $strongThreshold
                ? 'إشارة شراء قوية ✅' : 'إشارة شراء معتدلة ✅';
        } elseif ($bear >= $effectiveThreshold && $bear > $bull * 1.1) {
            $direction = 'SELL';
            $reason = $bear >= $strongThreshold
                ? 'إشارة بيع قوية ✅' : 'إشارة بيع معتدلة ✅';
        } else {
            // سبب محدد للانتظار
            if ($dominant < $threshold) {
                $needed = round($threshold - $dominant, 1);
                $side   = $bull > $bear ? 'شراء' : 'بيع';
                $reason = "نقاط {$side} = " . round($dominant, 1) . " — تحتاج {$needed} نقطة أخرى ⏳";
            } elseif (abs($bull - $bear) < $bull * 0.1) {
                $reason = "الإشارات متعادلة ({$bull} شراء vs {$bear} بيع) — انتظر ⏳";
            } else {
                $reason = "الإشارة ضعيفة ضد الاتجاه الساعة ({$trend1h}) ⏳";
            }
        }

        return [
            'direction'  => $direction,
            'strength'   => $strength,
            'bull'       => round($bull, 2),
            'bear'       => round($bear, 2),
            'threshold'  => $effectiveThreshold,
            'reason'     => $reason,
            'tags'       => array_unique($tags),
            'warnings'   => $warnings,
            'trend_1h'   => $trend1h,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // خطة التداول
    // ──────────────────────────────────────────────────────────────
    private function buildPlan(float $price, string $dir, float $atr, array $swing, array $cfg): ?array
    {
        $spread = $cfg['spread'];
        $slMult = $cfg['sl_mult'];
        $slMin  = $cfg['sl_min'];
        $slMax  = $cfg['sl_max'];

        // SL مبني على Swing + ATR buffer
        if ($dir === 'BUY') {
            $rawSL  = $price - $swing['low'];
            $slDist = max($slMin, min($slMax, max($rawSL + $atr * 0.3, $atr * $slMult)));
            $entry  = round($price + $spread, 2);
            $sl     = round($entry - $slDist, 2);
            $tp1    = round($entry + $atr * $cfg['tp1_mult'], 2);
            $tp2    = round($entry + $atr * $cfg['tp2_mult'], 2);
            $tp3    = round($entry + $atr * $cfg['tp3_mult'], 2);
        } else {
            $rawSL  = $swing['high'] - $price;
            $slDist = max($slMin, min($slMax, max($rawSL + $atr * 0.3, $atr * $slMult)));
            $entry  = round($price - $spread, 2);
            $sl     = round($entry + $slDist, 2);
            $tp1    = round($entry - $atr * $cfg['tp1_mult'], 2);
            $tp2    = round($entry - $atr * $cfg['tp2_mult'], 2);
            $tp3    = round($entry - $atr * $cfg['tp3_mult'], 2);
        }

        $tp1Dist  = round(abs($tp1 - $entry), 4);
        $rrRatio  = round($tp1Dist / $slDist, 2);

        $maxRisk  = ($cfg['account'] * $cfg['risk_pct']) / 100;
        $lot      = max($cfg['min_lot'], round($maxRisk / ($slDist * $cfg['oz_per_lot']), 3));
        $riskUSD  = round($lot * $slDist * $cfg['oz_per_lot'], 2);
        $profitT1 = round($lot * $tp1Dist * $cfg['oz_per_lot'], 2);
        $profitT2 = round($lot * abs($tp2 - $entry) * $cfg['oz_per_lot'], 2);

        return [
            'entry'      => $entry,
            'sl'         => $sl,
            'tp1'        => $tp1,
            'tp2'        => $tp2,
            'tp3'        => $tp3,
            'sl_dist'    => round($slDist, 2),
            'tp1_dist'   => round($tp1Dist, 2),
            'lot'        => $lot,
            'risk_usd'   => $riskUSD,
            'risk_pct'   => round($riskUSD / $cfg['account'] * 100, 1),
            'profit_tp1' => $profitT1,
            'profit_tp2' => $profitT2,
            'rr_tp1'     => $rrRatio,
            'rr_tp2'     => round(abs($tp2 - $entry) / $slDist, 2),
            'swing_ref'  => $dir === 'BUY' ? $swing['low'] : $swing['high'],
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // MAIN ANALYSIS
    // ──────────────────────────────────────────────────────────────
    public function getAnalysis(Request $request)
    {
        $asset   = $request->get('asset', 'gold');
        $cfg     = self::SYMBOLS[$asset] ?? self::SYMBOLS['gold'];
        $symbol  = $cfg['symbol'];
        $tdKey   = env('TWELVEDATA_API_KEY');
        $groqKey = env('GROQ_API_KEY');

        if (!$tdKey || !$groqKey) {
            return response()->json(['error' => 'API keys missing'], 500);
        }

        try {
            // ─── السعر اللحظي ───
            $priceCache = Cache::remember("price_{$asset}_v7", 8, function () use ($tdKey, $cfg) {
                try {
                    $r = Http::timeout(5)->get(
                        "https://api.twelvedata.com/price?symbol={$cfg['symbol']}&apikey={$tdKey}"
                    )->json();
                    if (isset($r['price']) && (float)$r['price'] > 0)
                        return ['price' => (float)$r['price'], 'change' => 0, 'change_pct' => 0];
                } catch (\Exception $e) {}

                try {
                    $q = Http::timeout(5)->get(
                        "https://api.twelvedata.com/quote?symbol={$cfg['symbol']}&apikey={$tdKey}"
                    )->json();
                    if (isset($q['close'])) return [
                        'price'      => (float)$q['close'],
                        'change'     => (float)($q['change'] ?? 0),
                        'change_pct' => (float)($q['percent_change'] ?? 0),
                    ];
                } catch (\Exception $e) {}
                return null;
            });

            // ─── الشموع — Cache 25s ───
            [$raw1m, $raw5m, $raw1h] = array_map(
                fn($tf) => Cache::remember("candles_{$asset}_{$tf}_v7", 25, fn() =>
                Http::timeout(12)->get("https://api.twelvedata.com/time_series", [
                    'symbol'     => $symbol,
                    'interval'   => $tf,
                    'outputsize' => 100,
                    'apikey'     => $tdKey,
                ])->json()
                ),
                ['1min', '5min', '1h']
            );

            if (!isset($raw5m['values']) || !isset($raw1m['values']) || !isset($raw1h['values'])) {
                return response()->json(['error' => 'فشل جلب بيانات السوق — تحقق من API key'], 500);
            }

            $c1m = array_reverse($raw1m['values']);
            $c5m = array_reverse($raw5m['values']);
            $c1h = array_reverse($raw1h['values']);
            $p1m = array_map(fn($c) => (float)$c['close'], $c1m);
            $p5m = array_map(fn($c) => (float)$c['close'], $c5m);
            $p1h = array_map(fn($c) => (float)$c['close'], $c1h);

            // ─── السعر الفعلي ───
            $lastCandle   = (float)end($p5m);
            $livePrice    = $priceCache['price'] ?? 0;
            $currentPrice = ($livePrice > 0 && abs($livePrice - $lastCandle) < $lastCandle * 0.05)
                ? $livePrice : $lastCandle;
            $priceSource  = ($livePrice > 0 && abs($livePrice - $lastCandle) < $lastCandle * 0.05)
                ? 'LIVE' : 'CANDLE';

            // ─── احتساب المؤشرات ───
            $atrVal  = $this->atr($c5m, 14);
            $ema12   = $this->ema($p5m, 12);
            $ema20   = $this->ema($p5m, 20);
            $ema50   = $this->ema($p5m, 50);
            $rsi1m   = $this->rsi($p1m, 14);
            $rsi5m   = $this->rsi($p5m, 14);
            $bbData  = $this->bb($p5m, 20);
            $stData  = $this->stoch($c5m, 14);
            $macdData= $this->macd($p5m);
            $pivData = $this->pivots(end($c5m));
            $candle  = $this->candleScore($c5m);
            $swing   = $this->swingLevels($c5m, 15);
            $trend1h = $this->trendBias($p1h);
            $sess    = $this->session();

            // ─── الإشارة ───
            $signal = $this->generateSignal(
                $currentPrice, $rsi1m, $rsi5m, $stData,
                $bbData, $macdData, $pivData,
                $ema12, $ema20, $ema50,
                $candle, $atrVal, $trend1h, $swing, $sess, $cfg
            );

            // ─── خطة التداول ───
            $plan = null;
            if ($signal['direction'] !== 'WAIT') {
                $plan = $this->buildPlan($currentPrice, $signal['direction'], $atrVal, $swing, $cfg);
            }

            // ─── AI ───
            $ai = $this->callAI(
                $groqKey, $signal, $plan, $currentPrice,
                $rsi1m, $rsi5m, $stData, $atrVal, $ema12, $ema20, $ema50,
                $bbData, $macdData, $pivData, $trend1h, $sess, $swing, $cfg
            );

            // ─── Labels ───
            $trendShort = ($ema12 > $ema20 && $ema20 > $ema50) ? 'صاعد ↑' :
                (($ema12 < $ema20 && $ema20 < $ema50) ? 'هابط ↓' : 'جانبي →');

            return response()->json([
                'asset'         => $asset,
                'symbol'        => $symbol,
                'label'         => $cfg['label'],
                'current_price' => $currentPrice,
                'price_source'  => $priceSource,
                'price_change'  => $priceCache['change']     ?? 0,
                'price_chg_pct' => $priceCache['change_pct'] ?? 0,
                'trend_short'   => $trendShort,
                'trend_1h'      => $trend1h,
                'session'       => $sess,
                'indicators'    => [
                    'ema'    => ['ema12' => $ema12, 'ema20' => $ema20, 'ema50' => $ema50],
                    'rsi'    => ['rsi_1m' => $rsi1m, 'rsi_5m' => $rsi5m],
                    'atr'    => $atrVal,
                    'stoch'  => $stData,
                    'macd'   => $macdData,
                    'bb'     => $bbData,
                    'pivots' => $pivData,
                    'candle' => $candle,
                    'swing'  => $swing,
                ],
                'trading_signal'=> $signal,
                'trade_plan'    => $plan,
                'recommendation'=> $ai,
                'time'          => now()->format('Y-m-d H:i:s'),
                'chart_data_5m' => array_slice($p5m, -40),
                'chart_times_5m'=> array_map(
                    fn($c) => substr($c['datetime'] ?? '', 11, 5),
                    array_slice($c5m, -40)
                ),
            ]);

        } catch (\Exception $e) {
            Log::error("Gold v7 [{$asset}]: " . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────
    // AI Prompt
    // ──────────────────────────────────────────────────────────────
    private function callAI(
        string $key, array $sig, ?array $plan, float $price,
        float $r1m, float $r5m, float $stoch, float $atr,
        ?float $e12, ?float $e20, ?float $e50,
        ?array $bb, ?array $macd, ?array $piv,
        string $trend1h, array $sess, array $swing, array $cfg
    ): string {
        $dir  = $sig['direction'];
        $dirAr= match($dir) { 'BUY' => 'شراء 📈', 'SELL' => 'بيع 📉', default => 'انتظار ⏳' };
        $tags = implode(', ', $sig['tags'] ?? []);
        $warn = implode(' | ', $sig['warnings'] ?? []);
        $label= $cfg['label'];

        if ($dir !== 'WAIT' && $plan) {
            $prompt = <<<TXT
محلل {$label} | الجلسة: {$sess['label']} | 1h: {$trend1h}
السعر: {$price} | {$dirAr} | قوة: {$sig['strength']}/10
Entry: {$plan['entry']} | SL: {$plan['sl']} (-{$plan['sl_dist']}) | TP1: {$plan['tp1']} | TP2: {$plan['tp2']} | TP3: {$plan['tp3']}
RR={$plan['rr_tp1']} | Lot={$plan['lot']} | خسارة=\${$plan['risk_usd']} | ربح TP1=\${$plan['profit_tp1']}
RSI 1m/5m: {$r1m}/{$r5m} | Stoch: {$stoch} | ATR: {$atr}
MACD hist: {$macd['hist']} | BB%B: {$bb['pct_b']} | EMA12/20/50: {$e12}/{$e20}/{$e50}
Swing H/L: {$swing['high']}/{$swing['low']} | الإشارات: {$tags}
{$warn}

اكتب تحليلاً (4 أسطر):
1. لماذا الإشارة {$dirAr} الآن؟
2. المستوى الذي يُلغي الإشارة (قبل SL)
3. متى تأخذ TP1 وتحرّك SL لـ Breakeven
TXT;
        } else {
            $prompt = <<<TXT
{$label} | انتظار | السعر: {$price} | 1h: {$trend1h} | {$sess['label']}
RSI 1m/5m: {$r1m}/{$r5m} | Stoch: {$stoch} | ATR: {$atr}
MACD hist: {$macd['hist']} | BB%B: {$bb['pct_b']}
EMA12/20/50: {$e12}/{$e20}/{$e50}
Pivots: S1={$piv['S1']} P={$piv['P']} R1={$piv['R1']}
نقاط شراء: {$sig['bull']} | نقاط بيع: {$sig['bear']} | المطلوب: {$sig['threshold']}
السبب: {$sig['reason']}

3 أسطر فقط:
1. لماذا الانتظار؟
2. ما الشرط بالضبط للدخول؟ (سعر محدد أو مؤشر محدد)
3. ما الإعداد الأرجح قادم وعند أي مستوى؟
TXT;
        }

        try {
            $res = Http::withHeaders([
                'Authorization' => "Bearer {$key}",
                'Content-Type'  => 'application/json',
            ])->timeout(25)->post('https://api.groq.com/openai/v1/chat/completions', [
                'model'    => 'llama-3.3-70b-versatile',
                'messages' => [
                    ['role' => 'system', 'content' =>
                        "أنت محلل {$cfg['label']} scalping محترف. ردودك مختصرة ومباشرة. ".
                        'ذكر أسعار محددة دائماً. لا تحذيرات عامة.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.05,
                'max_tokens'  => 380,
            ]);
            return $res->json()['choices'][0]['message']['content'] ?? '⚠️ تعذّر التحليل';
        } catch (\Exception $e) {
            return '⚠️ تعذّر الاتصال بالذكاء الاصطناعي';
        }
    }
}
