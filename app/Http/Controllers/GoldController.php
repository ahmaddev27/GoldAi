<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            'risk_pct'     => 1.5,    // متوازن لرأس مال 100$
            'oz_per_lot'   => 100,
            'min_lot'      => 0.01,
            'spread'       => 0.30,
            'atr_min'      => 0.4,
            'sl_min'       => 0.8,    // SL أدنى معقول للسكالبينج
            'sl_max'       => 1.5,    // = 1.5% × $100 / (0.01 × 100) — يطابق حد المخاطرة
            'tp1_mult'     => 1.5,    // RR_TP1 ≈ 1.5
            'tp2_mult'     => 2.5,
            'tp3_mult'     => 4.0,
            'sl_mult'      => 1.0,    // SL = 1.0 × ATR
            'label'        => 'الذهب XAU/USD',
        ],
        'btc' => [
            'symbol'       => 'BTC/USD',
            'account'      => 100,
            'risk_pct'     => 1.5,
            'oz_per_lot'   => 1,
            'min_lot'      => 0.001,
            'spread'       => 25.0,
            'atr_min'      => 50.0,
            'sl_min'       => 50.0,
            'sl_max'       => 1500.0, // = 1.5% × $100 / (0.001 × 1)
            'tp1_mult'     => 1.5,
            'tp2_mult'     => 2.5,
            'tp3_mult'     => 4.0,
            'sl_mult'      => 1.0,
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
    /**
     * كشف Rate Limit من رد TwelveData
     * يعيد ['limited' => bool, 'message' => string|null]
     *
     * TwelveData يُرجع 429 إما كـ HTTP status أو في JSON body:
     *   {"code": 429, "status": "error", "message": "..."}
     */
    private function detectRateLimit($response): array
    {
        // 1) HTTP status صريح
        if (is_object($response) && method_exists($response, 'status')) {
            $status = $response->status();
            if ($status === 429) {
                return ['limited' => true, 'message' => 'TwelveData rate limit (HTTP 429)'];
            }
        }

        // 2) JSON body فيه code=429 أو status=error
        $body = is_object($response) && method_exists($response, 'json')
            ? $response->json()
            : $response;

        if (is_array($body)) {
            $code = $body['code'] ?? null;
            $stat = $body['status'] ?? null;
            if ($code === 429 || (string)$code === '429') {
                $msg = $body['message'] ?? 'rate limit exceeded';
                return ['limited' => true, 'message' => $msg];
            }
            if ($stat === 'error' && isset($body['message']) &&
                stripos($body['message'], 'API credit') !== false) {
                return ['limited' => true, 'message' => $body['message']];
            }
        }

        return ['limited' => false, 'message' => null];
    }

    /**
     * بناء رد 429 موحّد للواجهة الأمامية
     */
    private function rateLimitResponse(string $detail = ''): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'error'        => 'RATE_LIMIT',
            'error_ar'     => '⚠️ تجاوز حد طلبات TwelveData (8 طلب/دقيقة على الخطة المجانية). انتظر ~60 ثانية ثم أعد المحاولة.',
            'detail'       => $detail,
            'retry_after'  => 60,
            'suggestions'  => [
                '⏱ انتظر دقيقة كاملة قبل التحديث التالي',
                '🌐 أغلق التابات الأخرى المفتوحة على نفس النظام',
                '⬆️ ترقية خطة TwelveData (Grow plan = 60 req/min)',
            ],
        ], 429)->header('Retry-After', '60');
    }

    public function getLivePrice(Request $request)
    {
        $asset = $request->get('asset', 'gold');
        $cfg   = self::SYMBOLS[$asset] ?? self::SYMBOLS['gold'];
        $key   = env('TWELVEDATA_API_KEY');

        if (!$key) return response()->json(['error' => 'API key missing'], 500);

        // ⚡ بدون Cache — جلب فوري لكل طلب (السعر اللحظي يتغير بسرعة)
        $data = null;
        $rateLimitHit = null;

        try {
            $resp = Http::timeout(6)->get(
                "https://api.twelvedata.com/price?symbol={$cfg['symbol']}&apikey={$key}"
            );
            $rl = $this->detectRateLimit($resp);
            if ($rl['limited']) {
                $rateLimitHit = $rl['message'];
            } else {
                $r = $resp->json();
                if (isset($r['price']) && (float)$r['price'] > 0) {
                    $data = [
                        'price'  => round((float)$r['price'], 2),
                        'source' => 'live',
                        'ts'     => now()->format('H:i:s'),
                    ];
                }
            }
        } catch (\Exception $e) {}

        if (!$data && !$rateLimitHit) {
            try {
                $resp = Http::timeout(6)->get(
                    "https://api.twelvedata.com/quote?symbol={$cfg['symbol']}&apikey={$key}"
                );
                $rl = $this->detectRateLimit($resp);
                if ($rl['limited']) {
                    $rateLimitHit = $rl['message'];
                } else {
                    $q = $resp->json();
                    if (isset($q['close'])) {
                        $data = [
                            'price'      => round((float)$q['close'], 2),
                            'source'     => 'quote',
                            'ts'         => now()->format('H:i:s'),
                            'change'     => round((float)($q['change'] ?? 0), 2),
                            'change_pct' => round((float)($q['percent_change'] ?? 0), 2),
                        ];
                    }
                }
            } catch (\Exception $e) {}
        }

        if ($rateLimitHit) {
            return $this->rateLimitResponse($rateLimitHit);
        }
        if (!$data) return response()->json(['error' => 'unavailable'], 503);
        return response()->json($data)
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('Pragma', 'no-cache');
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

    private function rsi(array $p, int $period = 14): ?float
    {
        $n = count($p);
        if ($n < $period + 1) return null;
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

    private function atr(array $candles, int $period = 14): ?float
    {
        if (count($candles) < $period + 1) return null;
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

    /**
     * ADX (Average Directional Index) — مرشّح قوة الاتجاه (Wilder)
     * < 20  → سوق جانبي/متذبذب (تجنّب التداول)
     * 20-25 → اتجاه ضعيف
     * > 25  → اتجاه قوي
     * > 40  → اتجاه قوي جداً (قد يكون منهك)
     */
    private function adx(array $candles, int $period = 14): ?array
    {
        $n = count($candles);
        if ($n < $period * 2 + 1) return null;

        $plusDM  = [];
        $minusDM = [];
        $trs     = [];

        for ($i = 1; $i < $n; $i++) {
            $h  = (float)$candles[$i]['high'];
            $l  = (float)$candles[$i]['low'];
            $pc = (float)$candles[$i - 1]['close'];
            $ph = (float)$candles[$i - 1]['high'];
            $pl = (float)$candles[$i - 1]['low'];

            $upMove   = $h - $ph;
            $downMove = $pl - $l;

            $plusDM[]  = ($upMove   > $downMove && $upMove   > 0) ? $upMove   : 0;
            $minusDM[] = ($downMove > $upMove   && $downMove > 0) ? $downMove : 0;
            $trs[]     = max($h - $l, abs($h - $pc), abs($l - $pc));
        }

        // Wilder smoothing
        $smTr  = array_sum(array_slice($trs,     0, $period));
        $smPdm = array_sum(array_slice($plusDM,  0, $period));
        $smMdm = array_sum(array_slice($minusDM, 0, $period));

        $dxList = [];
        for ($i = $period; $i < count($trs); $i++) {
            $smTr  = $smTr  - ($smTr  / $period) + $trs[$i];
            $smPdm = $smPdm - ($smPdm / $period) + $plusDM[$i];
            $smMdm = $smMdm - ($smMdm / $period) + $minusDM[$i];

            if ($smTr == 0) continue;
            $pDi = 100 * ($smPdm / $smTr);
            $mDi = 100 * ($smMdm / $smTr);
            $sum = $pDi + $mDi;
            $dx  = $sum == 0 ? 0 : 100 * abs($pDi - $mDi) / $sum;
            $dxList[] = $dx;
        }

        if (count($dxList) < $period) return null;

        // ADX = Wilder average of DX
        $adx = array_sum(array_slice($dxList, 0, $period)) / $period;
        for ($i = $period; $i < count($dxList); $i++) {
            $adx = ($adx * ($period - 1) + $dxList[$i]) / $period;
        }

        // قيم +DI / -DI الحالية
        $pDiNow = $smTr == 0 ? 0 : 100 * ($smPdm / $smTr);
        $mDiNow = $smTr == 0 ? 0 : 100 * ($smMdm / $smTr);

        $strength = 'WEAK';
        if      ($adx >= 40) $strength = 'VERY_STRONG';
        elseif  ($adx >= 25) $strength = 'STRONG';
        elseif  ($adx >= 20) $strength = 'MODERATE';
        else                 $strength = 'RANGING';

        return [
            'adx'      => round($adx, 2),
            'plus_di'  => round($pDiNow, 2),
            'minus_di' => round($mDiNow, 2),
            'strength' => $strength,
            'bull'     => $pDiNow > $mDiNow,
        ];
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

    private function stoch(array $candles, int $period = 14): ?float
    {
        if (count($candles) < $period) return null;
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

    /**
     * News Filter — منع التداول قبل/بعد الأخبار عالية التأثير
     * يستخدم نوافذ زمنية ثابتة بتوقيت UTC تغطي معظم الأخبار المؤثرة على الذهب
     *
     * نوافذ الخطر الرئيسية (UTC):
     * - 12:25–13:05 → ECB rate decision, EU CPI/PPI
     * - 13:25–14:05 → US data window (NFP, CPI, PPI, Retail Sales, GDP)
     * - 14:25–15:05 → ISM, Consumer Confidence, Crude Inventories
     * - 18:55–19:35 → FOMC decision (8 مرات/سنة، أربعاء)
     * - 21:55–22:30 → New Zealand/Australian data
     */
    private function newsFilter(): array
    {
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $h      = (int)$now->format('G');
        $m      = (int)$now->format('i');
        $hm     = $h * 60 + $m;            // دقائق منذ منتصف الليل UTC
        $dow    = (int)$now->format('w');  // 0=أحد، 5=جمعة
        $dom    = (int)$now->format('j');  // يوم الشهر

        $windows = [
            ['start' => 12*60+25, 'end' => 13*60+5,  'name' => 'ECB / EU Data',          'severity' => 'HIGH'],
            ['start' => 13*60+25, 'end' => 14*60+5,  'name' => 'US Economic Data',       'severity' => 'CRITICAL'],
            ['start' => 14*60+25, 'end' => 15*60+5,  'name' => 'ISM / Confidence',       'severity' => 'MEDIUM'],
            ['start' => 18*60+55, 'end' => 19*60+35, 'name' => 'FOMC Window',            'severity' => 'CRITICAL'],
        ];

        // NFP يوم الجمعة الأولى من الشهر (1-7 جمعة)
        if ($dow === 5 && $dom <= 7) {
            $windows[] = ['start' => 13*60+25, 'end' => 14*60+35, 'name' => 'NFP Release', 'severity' => 'EXTREME'];
        }

        foreach ($windows as $w) {
            if ($hm >= $w['start'] && $hm <= $w['end']) {
                $endsIn = $w['end'] - $hm;
                return [
                    'blocked'  => true,
                    'name'     => $w['name'],
                    'severity' => $w['severity'],
                    'reason'   => "⛔ نافذة أخبار عالية التأثير: {$w['name']} ({$w['severity']}) — تنتهي خلال {$endsIn} دقيقة",
                    'ends_in'  => $endsIn,
                ];
            }
        }

        // تحذير قبل 15 دقيقة من نافذة قادمة
        foreach ($windows as $w) {
            $minUntil = $w['start'] - $hm;
            if ($minUntil > 0 && $minUntil <= 15) {
                return [
                    'blocked'    => true,
                    'name'       => $w['name'],
                    'severity'   => $w['severity'],
                    'reason'     => "⚠️ {$w['name']} خلال {$minUntil} دقيقة — لا تفتح صفقة جديدة",
                    'starts_in'  => $minUntil,
                ];
            }
        }

        return ['blocked' => false];
    }

    /**
     * RSI Divergence — يكتشف الانعكاسات القوية باستخدام مقارنة نصفي النافذة
     * Bearish: النصف الثاني صنع HH في السعر لكن RSI أضعف من قمة النصف الأول
     * Bullish: النصف الثاني صنع LL في السعر لكن RSI أقوى من قاع النصف الأول
     *
     * approach بسيط وقوي: لا يعتمد على peak detection حساس
     */
    private function rsiDivergence(array $prices, int $rsiPeriod = 14, int $lookback = 50): ?array
    {
        $n = count($prices);
        // إذا البيانات أقل من المطلوب، قلّص lookback تلقائياً
        if ($n < $rsiPeriod + 20) return null;
        $lookback = min($lookback, $n - $rsiPeriod - 1);
        if ($lookback < 20) return null;

        // احسب سلسلة RSI لكل النقاط في النافذة
        $rsiSeries = [];
        for ($i = $n - $lookback; $i < $n; $i++) {
            $slice = array_slice($prices, 0, $i + 1);
            $r     = $this->rsi($slice, $rsiPeriod);
            if ($r !== null) $rsiSeries[$i] = $r;
        }

        if (count($rsiSeries) < $lookback - 5) return null;

        // قسّم النافذة إلى نصفين
        $half  = (int)floor($lookback / 2);
        $startIdx = $n - $lookback;
        $midIdx   = $startIdx + $half;

        // النصف الأول: ابحث عن أعلى وأدنى نقطة + قيمة RSI عندها
        $h1Price = -INF; $h1Rsi = 0; $h1At = $startIdx;
        $l1Price = INF;  $l1Rsi = 0; $l1At = $startIdx;
        for ($i = $startIdx; $i < $midIdx; $i++) {
            if (!isset($rsiSeries[$i])) continue;
            if ($prices[$i] > $h1Price) { $h1Price = $prices[$i]; $h1Rsi = $rsiSeries[$i]; $h1At = $i; }
            if ($prices[$i] < $l1Price) { $l1Price = $prices[$i]; $l1Rsi = $rsiSeries[$i]; $l1At = $i; }
        }

        // النصف الثاني
        $h2Price = -INF; $h2Rsi = 0; $h2At = $midIdx;
        $l2Price = INF;  $l2Rsi = 0; $l2At = $midIdx;
        for ($i = $midIdx; $i < $n; $i++) {
            if (!isset($rsiSeries[$i])) continue;
            if ($prices[$i] > $h2Price) { $h2Price = $prices[$i]; $h2Rsi = $rsiSeries[$i]; $h2At = $i; }
            if ($prices[$i] < $l2Price) { $l2Price = $prices[$i]; $l2Rsi = $rsiSeries[$i]; $l2At = $i; }
        }

        $type = null;
        $strength = 0;

        // Bearish: HH في السعر + LH في RSI، والـ RSI الأول كان مرتفع (>55)
        // ملاحظة: حتى ارتفاع طفيف في السعر مع انخفاض RSI = divergence حقيقي
        $priceUp = $h2Price > $h1Price;             // أي ارتفاع
        $rsiDown = $h1Rsi - $h2Rsi >= 5;            // فرق ≥ 5 نقاط RSI (لتجنب الإشارات الضعيفة)
        if ($priceUp && $rsiDown && $h1Rsi > 60) {
            $type = 'BEARISH';
            $strength = round($h1Rsi - $h2Rsi, 2);
        }

        // Bullish: LL في السعر + HL في RSI، والـ RSI الأول كان منخفض (<45)
        if ($type === null) {
            $priceDown = $l2Price < $l1Price;
            $rsiUp     = $l2Rsi - $l1Rsi >= 5;
            if ($priceDown && $rsiUp && $l1Rsi < 40) {
                $type = 'BULLISH';
                $strength = round($l2Rsi - $l1Rsi, 2);
            }
        }

        if ($type === null) return null;
        return [
            'type'     => $type,
            'strength' => $strength,
        ];
    }

    /**
     * Fibonacci Retracement من آخر swing كبير
     * يحدّد المستوى الذي يقف عنده السعر حالياً
     */
    private function fibonacci(array $candles, int $lookback = 50): ?array
    {
        $n = count($candles);
        if ($n < $lookback) return null;
        $slice = array_slice($candles, -$lookback);

        $high = max(array_map(fn($c) => (float)$c['high'], $slice));
        $low  = min(array_map(fn($c) => (float)$c['low'],  $slice));
        $range = $high - $low;
        if ($range <= 0) return null;

        // أي من القمة/القاع جاء أخيراً → يحدد اتجاه الـ retracement
        $highIdx = 0; $lowIdx = 0;
        foreach ($slice as $i => $c) {
            if ((float)$c['high'] >= $high) $highIdx = $i;
            if ((float)$c['low']  <= $low)  $lowIdx  = $i;
        }
        $direction = $highIdx > $lowIdx ? 'UP' : 'DOWN'; // UP: تصاعد ثم retracement هبوطي

        // حساب المستويات
        $levels = [];
        $ratios = [0, 0.236, 0.382, 0.5, 0.618, 0.786, 1];
        if ($direction === 'UP') {
            // من low إلى high — retracement هبوطي
            foreach ($ratios as $r) {
                $levels[(string)$r] = round($high - $range * $r, 2);
            }
        } else {
            // من high إلى low — retracement صعودي
            foreach ($ratios as $r) {
                $levels[(string)$r] = round($low + $range * $r, 2);
            }
        }

        // امتدادات (للـ TP)
        $ext = [
            '1.272' => $direction === 'UP'
                ? round($high + $range * 0.272, 2)
                : round($low  - $range * 0.272, 2),
            '1.618' => $direction === 'UP'
                ? round($high + $range * 0.618, 2)
                : round($low  - $range * 0.618, 2),
        ];

        // أي مستوى أقرب للسعر الحالي (ضمن 30% من ATR)
        $lastClose = (float)end($slice)['close'];
        $closest = null;
        $closestDist = PHP_FLOAT_MAX;
        foreach ($levels as $name => $lvl) {
            $d = abs($lastClose - $lvl);
            if ($d < $closestDist) {
                $closestDist = $d;
                $closest = ['ratio' => (float)$name, 'price' => $lvl, 'distance' => round($d, 2)];
            }
        }

        return [
            'direction' => $direction,
            'high'      => round($high, 2),
            'low'       => round($low, 2),
            'levels'    => $levels,
            'extensions'=> $ext,
            'closest'   => $closest,
        ];
    }

    // ══════════════════════════════════════════════════════════════
    //  نظام الإشارة المُصلح — يعمل بشكل فعلي
    //  المشكلة القديمة: شروط مستحيلة تحجب كل شيء
    //  الحل: نقاط مرجّحة بشروط واضحة وقابلة للتحقق
    // ══════════════════════════════════════════════════════════════
    private function generateSignal(
        float  $price,
        ?float $rsi1m,
        ?float $rsi5m,
        ?float $stoch,
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
        array  $cfg,
        ?array $adx        = null,
        ?array $divergence = null,
        ?array $fib        = null,
        ?array $news       = null
    ): array {

        // ──────────────────────────────────────────────────────
        // نقاط الشراء والبيع (كل مؤشر يُعطي نقاطه بشكل مستقل)
        // المؤشرات الـ null تُتخطّى (لا تساهم في النقاط)
        // ──────────────────────────────────────────────────────
        $bull = 0.0;
        $bear = 0.0;
        $tags = [];

        // ① RSI 1m — الأثقل وزناً
        if ($rsi1m !== null) {
            if      ($rsi1m <= 20) { $bull += 3.0; $tags[] = 'RSI1m_EXTREME_LOW'; }
            elseif  ($rsi1m <= 30) { $bull += 2.0; $tags[] = 'RSI1m_OVERSOLD'; }
            elseif  ($rsi1m <= 40) { $bull += 1.0; $tags[] = 'RSI1m_LOW'; }
            elseif  ($rsi1m <= 50) { $bull += 0.4; }
            elseif  ($rsi1m <= 60) { $bear += 0.4; }
            elseif  ($rsi1m <= 70) { $bear += 1.0; $tags[] = 'RSI1m_HIGH'; }
            elseif  ($rsi1m <= 80) { $bear += 2.0; $tags[] = 'RSI1m_OVERBOUGHT'; }
            else                   { $bear += 3.0; $tags[] = 'RSI1m_EXTREME_HIGH'; }
        }

        // ② RSI 5m — وزن متوسط
        if ($rsi5m !== null) {
            if      ($rsi5m <= 25) { $bull += 2.5; $tags[] = 'RSI5m_EXTREME_LOW'; }
            elseif  ($rsi5m <= 35) { $bull += 1.5; $tags[] = 'RSI5m_OVERSOLD'; }
            elseif  ($rsi5m <= 45) { $bull += 0.7; $tags[] = 'RSI5m_LOW'; }
            elseif  ($rsi5m <= 55) { /* neutral */ }
            elseif  ($rsi5m <= 65) { $bear += 0.7; $tags[] = 'RSI5m_HIGH'; }
            elseif  ($rsi5m <= 75) { $bear += 1.5; $tags[] = 'RSI5m_OVERBOUGHT'; }
            else                   { $bear += 2.5; $tags[] = 'RSI5m_EXTREME_HIGH'; }
        }

        // ③ Stochastic
        if ($stoch !== null) {
            if      ($stoch <= 15) { $bull += 2.0; $tags[] = 'STOCH_EXTREME_LOW'; }
            elseif  ($stoch <= 25) { $bull += 1.3; $tags[] = 'STOCH_OVERSOLD'; }
            elseif  ($stoch <= 40) { $bull += 0.5; $tags[] = 'STOCH_LOW'; }
            elseif  ($stoch >= 85) { $bear += 2.0; $tags[] = 'STOCH_EXTREME_HIGH'; }
            elseif  ($stoch >= 75) { $bear += 1.3; $tags[] = 'STOCH_OVERBOUGHT'; }
            elseif  ($stoch >= 60) { $bear += 0.5; $tags[] = 'STOCH_HIGH'; }
        }

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

        // ⑪ ADX — مرشّح قوة الاتجاه (عامل مضاعفة وليس نقاط مباشرة)
        $adxMultiplier = 1.0;
        if ($adx) {
            $tags[] = "ADX_{$adx['strength']}_" . round($adx['adx']);
            switch ($adx['strength']) {
                case 'VERY_STRONG':
                    $adxMultiplier = 1.3; // اتجاه قوي جداً → ضاعف النقاط في الاتجاه
                    if ($adx['bull']) $bull *= $adxMultiplier;
                    else              $bear *= $adxMultiplier;
                    break;
                case 'STRONG':
                    $adxMultiplier = 1.15;
                    if ($adx['bull']) $bull *= $adxMultiplier;
                    else              $bear *= $adxMultiplier;
                    break;
                case 'MODERATE':
                    // لا تغيير
                    break;
                case 'RANGING':
                    // سوق جانبي → تخفيض حاد للنقاط
                    $bull *= 0.4;
                    $bear *= 0.4;
                    break;
            }
        }

        // ⑫ RSI Divergence — أقوى إشارة انعكاسية
        if ($divergence) {
            if ($divergence['type'] === 'BULLISH') {
                $bull += 2.5;
                $tags[] = 'RSI_BULL_DIVERGENCE';
                $bear = max(0, $bear - 1.0); // عقوبة على البيع
            } else if ($divergence['type'] === 'BEARISH') {
                $bear += 2.5;
                $tags[] = 'RSI_BEAR_DIVERGENCE';
                $bull = max(0, $bull - 1.0);
            }
        }

        // ⑬ Fibonacci — مكافأة الدخول عند مستويات الـ golden ratio
        if ($fib && isset($fib['closest'])) {
            $closestRatio = $fib['closest']['ratio'];
            $fibProx      = $atr * 0.5; // قرب 0.5 ATR
            if ($fib['closest']['distance'] <= $fibProx) {
                if (in_array($closestRatio, [0.382, 0.5, 0.618], true)) {
                    if ($fib['direction'] === 'UP') {
                        // retracement هبوطي في اتجاه صاعد → فرصة شراء
                        $bull += 1.5;
                        $tags[] = "FIB_{$closestRatio}_BUY";
                    } else {
                        $bear += 1.5;
                        $tags[] = "FIB_{$closestRatio}_SELL";
                    }
                } elseif (in_array($closestRatio, [0.236, 0.786], true)) {
                    if ($fib['direction'] === 'UP') $bull += 0.5;
                    else                            $bear += 0.5;
                }
            }
        }

        // ⑭ تحذيرات (تخفض النقاط)
        $warnings = [];
        if ($session['asian']) {
            $warnings[] = '⚠️ الجلسة الآسيوية — تقلّب منخفض';
            $bull *= 0.7; $bear *= 0.7; // تخفيض لكن لا حجب كامل
        }
        if ($atr < $cfg['atr_min']) {
            $warnings[] = "⚠️ ATR منخفض ({$atr}) — سوق راكد";
            $bull *= 0.8; $bear *= 0.8;
        }

        // ⑮ News Filter — حجب كامل عند الأخبار عالية التأثير
        if ($news && !empty($news['blocked'])) {
            $warnings[] = $news['reason'];
            return [
                'direction' => 'WAIT',
                'strength'  => 0,
                'bull'      => round($bull, 2),
                'bear'      => round($bear, 2),
                'threshold' => 999, // لإظهار أن السبب خارجي
                'reason'    => $news['reason'],
                'tags'      => array_unique($tags),
                'warnings'  => $warnings,
                'trend_1h'  => $trend1h,
                'news_block'=> true,
            ];
        }

        // ──────────────────────────────────────────────────────
        // العتبات — عدّلناها لتكون واقعية
        // الحد الأدنى: 4.0 نقطة (يمكن تحقيقه بـ RSI+EMA+MACD)
        // الحد القوي: 7.0 نقطة
        // ──────────────────────────────────────────────────────
        $threshold      = 4.0;
        $strongThreshold= 7.0;

        // إذا ADX يشير لسوق جانبي قوي → ارفع العتبة
        if ($adx && $adx['strength'] === 'RANGING') {
            $threshold       += 2.0;
            $strongThreshold += 2.0;
            $warnings[] = "⚠️ ADX={$adx['adx']} (سوق جانبي) — تجنّب الصفقات الاتجاهية";
        }

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
            } elseif (abs($bull - $bear) < max($bull, $bear, 1) * 0.1) {
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
            'adx_filter' => $adx['strength'] ?? 'N/A',
            'divergence' => $divergence['type'] ?? null,
            'news_block' => false,
        ];
    }

    // ──────────────────────────────────────────────────────────────
    // خطة التداول — Risk-First Sizing لرأس مال 100$ بلوت 0.01
    // المنطق: SL يُحسب من ATR فقط (بدون تضخيم بالـ swing)
    //         إذا تجاوز SL الحد المسموح → ترجع skip → WAIT
    // ──────────────────────────────────────────────────────────────
    private function buildPlan(float $price, string $dir, float $atr, array $swing, array $cfg): ?array
    {
        $spread = $cfg['spread'];
        $slMin  = $cfg['sl_min'];
        $slMax  = $cfg['sl_max'];

        // ① أقصى SL مسموح به (محسوب من حد المخاطرة الفعلي مع min_lot وبعد خصم spread)
        // المعادلة: (slDist + spread) × min_lot × oz_per_lot ≤ maxRiskUSD
        // إذن: slDist ≤ maxRiskUSD / (min_lot × oz_per_lot) − spread
        $maxRiskUSD   = ($cfg['account'] * $cfg['risk_pct']) / 100;
        $minRiskPerPt = $cfg['min_lot'] * $cfg['oz_per_lot']; // أصغر $ لكل 1$ سعر
        $maxSlAllowed = ($maxRiskUSD / $minRiskPerPt) - $spread; // gold: 1.5 − 0.3 = $1.20
        $hardSlMax    = min($slMax, $maxSlAllowed);

        // ② SL مُرشّح من ATR فقط
        $atrSl = max($slMin, $atr * $cfg['sl_mult']);

        // ③ swing كحد ضيق اختياري + كشف الفخ (fakeout)
        if ($dir === 'BUY') {
            // فخ: السعر تحت دعم الـ swing
            if ($price < $swing['low']) {
                return ['skip' => true, 'reason' => 'السعر تحت دعم الـ swing — احتمال فخ هبوطي'];
            }
            $swingDist = $price - $swing['low'];
            // إذا الـ swing أقرب من ATR استخدمه (مع buffer صغير)
            if ($swingDist > 0 && $swingDist < $atrSl) {
                $atrSl = max($slMin, $swingDist + $spread);
            }
        } else { // SELL
            if ($price > $swing['high']) {
                return ['skip' => true, 'reason' => 'السعر فوق مقاومة الـ swing — احتمال فخ صعودي'];
            }
            $swingDist = $swing['high'] - $price;
            if ($swingDist > 0 && $swingDist < $atrSl) {
                $atrSl = max($slMin, $swingDist + $spread);
            }
        }

        // ④ تحقّق أن SL ضمن الحد المسموح — أو تخطّي
        if ($atrSl > $hardSlMax) {
            $slShown = round($atrSl, 2);
            return [
                'skip'   => true,
                'reason' => "السوق متقلب جداً (SL مطلوب \${$slShown}) — رأس مال \$100 لا يحتمل",
            ];
        }
        $slDist = round($atrSl, 2);

        // ⑤ مسافات SL الحقيقية (مع spread)
        $slDistReal = $slDist + $spread;

        // ⑥ مسافات TP الحقيقية مع إنفاذ RR أدنى
        // RR_TP1 ≥ 1.3 (إيجابي بعد الـ spread)، RR_TP2 ≥ 2.2، RR_TP3 ≥ 3.5
        // الـ floor هذا يحمي من ATR صغير + spread نسبي كبير
        $tp1DistAtr  = $atr * $cfg['tp1_mult'] - $spread;
        $tp2DistAtr  = $atr * $cfg['tp2_mult'] - $spread;
        $tp3DistAtr  = $atr * $cfg['tp3_mult'] - $spread;
        $tp1DistReal = max($tp1DistAtr, $slDistReal * 1.3);
        $tp2DistReal = max($tp2DistAtr, $slDistReal * 2.2);
        $tp3DistReal = max($tp3DistAtr, $slDistReal * 3.5);

        // ⑦ احسب أسعار الدخول والخروج (السعر الفعلي على الرسم البياني)
        // entry = price + spread (BUY ask) أو price - spread (SELL bid)
        // tp_price = entry ± (tp_dist_real + spread) — لأن البروكر يأخذ spread عند الإغلاق
        if ($dir === 'BUY') {
            $entry = round($price + $spread, 2);
            $sl    = round($entry - $slDist, 2);
            $tp1   = round($entry + $tp1DistReal + $spread, 2);
            $tp2   = round($entry + $tp2DistReal + $spread, 2);
            $tp3   = round($entry + $tp3DistReal + $spread, 2);
        } else {
            $entry = round($price - $spread, 2);
            $sl    = round($entry + $slDist, 2);
            $tp1   = round($entry - $tp1DistReal - $spread, 2);
            $tp2   = round($entry - $tp2DistReal - $spread, 2);
            $tp3   = round($entry - $tp3DistReal - $spread, 2);
        }

        // ⑦ Lot بـ floor دائماً (لا تكسر حد المخاطرة بالتقريب)
        $rawLot = $maxRiskUSD / ($slDistReal * $cfg['oz_per_lot']);
        if ($cfg['min_lot'] >= 0.01) {
            $lot = max($cfg['min_lot'], floor($rawLot * 100) / 100); // 2 منازل
        } else {
            $lot = max($cfg['min_lot'], floor($rawLot * 1000) / 1000); // 3 منازل (BTC)
        }

        $riskUSD = round($lot * $slDistReal * $cfg['oz_per_lot'], 2);

        // ⑧ تحقّق نهائي — لا يجب أن نتجاوز نسبة المخاطرة
        if ($riskUSD > $maxRiskUSD * 1.05) {
            return ['skip' => true, 'reason' => 'حساب الحجم تجاوز حد المخاطرة — تخطّي'];
        }

        $profitT1 = round($lot * $tp1DistReal * $cfg['oz_per_lot'], 2);
        $profitT2 = round($lot * $tp2DistReal * $cfg['oz_per_lot'], 2);
        $rrTp1    = round($tp1DistReal / $slDistReal, 2);
        $rrTp2    = round($tp2DistReal / $slDistReal, 2);

        // ⑨ Trailing Stop Plan — قواعد إدارة الصفقة بعد الدخول
        // الفلسفة: تأمين الربح تدريجياً وتحويل الصفقة إلى "بدون مخاطرة"
        // ملاحظة: لـ lot صغير (0.01) لا يمكن التقسيم → نستخدم استراتيجية SL trailing فقط
        $minLot     = $cfg['min_lot'];
        $canSplit3  = $lot >= $minLot * 4;  // ≥ 0.04 → 3 أجزاء حقيقية (2/1/1 على الأقل)
        $canSplit2  = $lot >= $minLot * 2;  // ≥ 0.02 → جزأين

        if ($canSplit3) {
            // 50% / 30% / 20% (lot كبير نسبياً)
            $partial1 = round(floor($lot * 0.5 / $minLot) * $minLot, 3);
            $partial2 = round(floor($lot * 0.3 / $minLot) * $minLot, 3);
            $remain   = round($lot - $partial1 - $partial2, 3);
            $strategy = 'SCALE_OUT_3';
        } elseif ($canSplit2) {
            // 50% / 50% (lot متوسط)
            $partial1 = round(floor($lot * 0.5 / $minLot) * $minLot, 3);
            $partial2 = 0;
            $remain   = round($lot - $partial1, 3);
            $strategy = 'SCALE_OUT_2';
        } else {
            // lot = min (0.01) → لا تقسيم — استراتيجية SL trailing فقط
            $partial1 = 0;
            $partial2 = 0;
            $remain   = $lot;
            $strategy = 'TRAIL_ONLY';
        }

        if ($strategy === 'TRAIL_ONLY') {
            // استراتيجية للـ lot الأدنى (0.01): تحريك SL تدريجياً بدون إغلاقات جزئية
            $trailingPlan = [
                'strategy' => 'TRAIL_ONLY',
                'note'     => "📌 لوت 0.01 لا يقبل التقسيم — نستخدم تحريك SL متدرّج",
                'step_1'   => [
                    'trigger' => "السعر يصل TP1 ({$tp1})",
                    'action'  => "حرّك SL إلى Entry ({$entry}) [Breakeven] — لا إغلاق",
                    'result'  => "الصفقة بدون مخاطرة. ربح مضمون: \$0 (worst case) → \${$profitT1}+ (best case)",
                ],
                'step_2'   => [
                    'trigger' => "السعر يصل TP2 ({$tp2})",
                    'action'  => "حرّك SL إلى TP1 ({$tp1}) — تأمين ربح \${$profitT1}",
                    'result'  => "ربح مضمون \${$profitT1} مهما حدث",
                ],
                'step_3'   => [
                    'trigger' => "السعر يصل TP3 ({$tp3}) — أو SL يُضرب",
                    'action'  => "أغلق الصفقة بالكامل ({$lot} لوت) عند TP3 أو دع SL يقفل",
                    'result'  => "أقصى ربح: \$" . round($lot * ($tp3 - $entry) * (($dir==='SELL')?-1:1) * $cfg['oz_per_lot'], 2),
                ],
                'partials' => ['tp1_lot' => 0, 'tp2_lot' => 0, 'tp3_lot' => $remain],
            ];
        } else {
            $pct1 = $strategy === 'SCALE_OUT_3' ? '50%' : '50%';
            $trailingPlan = [
                'strategy' => $strategy,
                'step_1'   => [
                    'trigger' => "السعر يصل TP1 ({$tp1})",
                    'action'  => "أغلق {$partial1} لوت ({$pct1}) + حرّك SL إلى Entry ({$entry}) [Breakeven]",
                    'result'  => "ربح مؤمَّن: \$" . round($partial1 * ($tp1 - $entry) * (($dir==='SELL')?-1:1) * $cfg['oz_per_lot'], 2)
                                . " — الباقي بدون مخاطرة",
                ],
                'step_2'   => $partial2 > 0 ? [
                    'trigger' => "السعر يصل TP2 ({$tp2})",
                    'action'  => "أغلق {$partial2} لوت (30%) + حرّك SL إلى TP1 ({$tp1})",
                    'result'  => "ربح إضافي مؤمَّن — الباقي يطارد الاتجاه",
                ] : [
                    'trigger' => "السعر يصل TP2 ({$tp2})",
                    'action'  => "حرّك SL إلى TP1 ({$tp1}) — تأمين ربح TP1",
                    'result'  => "ربح TP1 مضمون على الباقي",
                ],
                'step_3'   => [
                    'trigger' => "السعر يصل TP3 ({$tp3}) أو SL يضرب TP1",
                    'action'  => "أغلق الباقي ({$remain} لوت) عند TP3",
                    'result'  => "أقصى ربح ممكن من الحركة",
                ],
                'partials' => [
                    'tp1_lot' => $partial1,
                    'tp2_lot' => $partial2,
                    'tp3_lot' => $remain,
                ],
            ];
        }

        return [
            'entry'        => $entry,
            'sl'           => $sl,
            'tp1'          => $tp1,
            'tp2'          => $tp2,
            'tp3'          => $tp3,
            'sl_dist'      => $slDist,
            'tp1_dist'     => round($tp1DistReal, 2),
            'lot'          => $lot,
            'risk_usd'     => $riskUSD,
            'risk_pct'     => round($riskUSD / $cfg['account'] * 100, 2),
            'profit_tp1'   => $profitT1,
            'profit_tp2'   => $profitT2,
            'rr_tp1'       => $rrTp1,
            'rr_tp2'       => $rrTp2,
            'swing_ref'    => $dir === 'BUY' ? $swing['low'] : $swing['high'],
            'trailing_plan'=> $trailingPlan,
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
            // ⚡ بدون Cache — جلب فوري للسعر اللحظي + فحص rate limit
            $priceCache  = null;
            $rateLimitMsg = null;

            try {
                $resp = Http::timeout(5)->get(
                    "https://api.twelvedata.com/price?symbol={$cfg['symbol']}&apikey={$tdKey}"
                );
                $rl = $this->detectRateLimit($resp);
                if ($rl['limited']) {
                    $rateLimitMsg = $rl['message'];
                } else {
                    $r = $resp->json();
                    if (isset($r['price']) && (float)$r['price'] > 0) {
                        $priceCache = ['price' => (float)$r['price'], 'change' => 0, 'change_pct' => 0];
                    }
                }
            } catch (\Exception $e) {}

            if (!$priceCache && !$rateLimitMsg) {
                try {
                    $resp = Http::timeout(5)->get(
                        "https://api.twelvedata.com/quote?symbol={$cfg['symbol']}&apikey={$tdKey}"
                    );
                    $rl = $this->detectRateLimit($resp);
                    if ($rl['limited']) {
                        $rateLimitMsg = $rl['message'];
                    } else {
                        $q = $resp->json();
                        if (isset($q['close'])) {
                            $priceCache = [
                                'price'      => (float)$q['close'],
                                'change'     => (float)($q['change'] ?? 0),
                                'change_pct' => (float)($q['percent_change'] ?? 0),
                            ];
                        }
                    }
                } catch (\Exception $e) {}
            }

            if ($rateLimitMsg) {
                return $this->rateLimitResponse("price endpoint: {$rateLimitMsg}");
            }

            // ⚡ بدون Cache — جلب فوري للشموع (4 timeframes متوازية)
            $tfs = ['1min', '5min', '1h', '1day'];
            $responses = Http::pool(fn ($pool) => array_map(
                fn($tf) => $pool->as($tf)->timeout(12)->get('https://api.twelvedata.com/time_series', [
                    'symbol'     => $symbol,
                    'interval'   => $tf,
                    'outputsize' => $tf === '1day' ? 2 : 100,
                    'apikey'     => $tdKey,
                ]),
                $tfs
            ));

            // فحص rate limit على كل الـ 4 timeframes
            foreach ($tfs as $tf) {
                $rl = $this->detectRateLimit($responses[$tf]);
                if ($rl['limited']) {
                    return $this->rateLimitResponse("time_series[{$tf}]: {$rl['message']}");
                }
            }

            $raw1m = $responses['1min']->json();
            $raw5m = $responses['5min']->json();
            $raw1h = $responses['1h']->json();
            $raw1d = $responses['1day']->json();

            if (!isset($raw5m['values']) || !isset($raw1m['values']) || !isset($raw1h['values'])) {
                return response()->json([
                    'error'    => 'INVALID_DATA',
                    'error_ar' => 'فشل جلب بيانات السوق — تحقق من API key أو رمز الأصل',
                    'detail'   => 'TwelveData لم يُرجع values في الشموع',
                ], 500);
            }

            $c1m = array_reverse($raw1m['values']);
            $c5m = array_reverse($raw5m['values']);
            $c1h = array_reverse($raw1h['values']);
            $p1m = array_map(fn($c) => (float)$c['close'], $c1m);
            $p5m = array_map(fn($c) => (float)$c['close'], $c5m);
            $p1h = array_map(fn($c) => (float)$c['close'], $c1h);

            // ─── السعر الفعلي مع تسامح ضيق 0.5% ───
            $lastCandle   = (float)end($p5m);
            $livePrice    = $priceCache['price'] ?? 0;
            $tolerance    = $lastCandle * 0.005; // 0.5%
            $useLive      = ($livePrice > 0 && abs($livePrice - $lastCandle) < $tolerance);
            $currentPrice = $useLive ? $livePrice : $lastCandle;
            $priceSource  = $useLive ? 'LIVE' : 'CANDLE';

            // ─── احتساب المؤشرات ───
            $atrVal  = $this->atr($c5m, 14);

            // ATR null = بيانات قاصرة → خطأ واضح بدلاً من حسابات وهمية
            if ($atrVal === null) {
                return response()->json([
                    'error' => 'بيانات السوق غير كافية لحساب ATR — انتظر شموع أكثر'
                ], 503);
            }

            $ema12   = $this->ema($p5m, 12);
            $ema20   = $this->ema($p5m, 20);
            $ema50   = $this->ema($p5m, 50);
            $rsi1m   = $this->rsi($p1m, 14);
            $rsi5m   = $this->rsi($p5m, 14);
            $bbData  = $this->bb($p5m, 20);
            $stData  = $this->stoch($c5m, 14);
            $macdData= $this->macd($p5m);

            // Pivots من اليوم السابق (ليس من شمعة 5 دقائق)
            $dailyValues = $raw1d['values'] ?? [];
            // twelvedata يُرجع الأحدث أولاً → اليوم السابق هو [1]، اليوم الحالي [0]
            $prevDay = $dailyValues[1] ?? ($dailyValues[0] ?? end($c5m));
            $pivData = $this->pivots($prevDay);

            $candle  = $this->candleScore($c5m);
            $swing   = $this->swingLevels($c5m, 15);
            $trend1h = $this->trendBias($p1h);
            $sess    = $this->session();

            // ─── المؤشرات الجديدة ───
            $adxData    = $this->adx($c5m, 14);
            $divergence = $this->rsiDivergence($p5m, 14, 30);
            $fibData    = $this->fibonacci($c5m, 50);
            $newsData   = $this->newsFilter();

            // ─── الإشارة ───
            $signal = $this->generateSignal(
                $currentPrice, $rsi1m, $rsi5m, $stData,
                $bbData, $macdData, $pivData,
                $ema12, $ema20, $ema50,
                $candle, $atrVal, $trend1h, $swing, $sess, $cfg,
                $adxData, $divergence, $fibData, $newsData
            );

            // ─── خطة التداول ───
            $plan = null;
            if ($signal['direction'] !== 'WAIT') {
                $plan = $this->buildPlan($currentPrice, $signal['direction'], $atrVal, $swing, $cfg);
                // إذا buildPlan رجع skip → حوّل الإشارة لـ WAIT
                if (is_array($plan) && !empty($plan['skip'])) {
                    $signal['direction'] = 'WAIT';
                    $signal['reason']    = $plan['reason'];
                    $signal['warnings'][] = '⚠️ ' . $plan['reason'];
                    $plan = null;
                }
            }

            // ─── AI ───
            $ai = $this->callAI(
                $groqKey, $signal, $plan, $currentPrice,
                $rsi1m, $rsi5m, $stData, $atrVal, $ema12, $ema20, $ema50,
                $bbData, $macdData, $pivData, $trend1h, $sess, $swing, $cfg,
                $adxData, $divergence, $fibData
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
                    'ema'        => ['ema12' => $ema12, 'ema20' => $ema20, 'ema50' => $ema50],
                    'rsi'        => ['rsi_1m' => $rsi1m, 'rsi_5m' => $rsi5m],
                    'atr'        => $atrVal,
                    'stoch'      => $stData,
                    'macd'       => $macdData,
                    'bb'         => $bbData,
                    'pivots'     => $pivData,
                    'candle'     => $candle,
                    'swing'      => $swing,
                    'adx'        => $adxData,
                    'divergence' => $divergence,
                    'fibonacci'  => $fibData,
                ],
                'news_filter'   => $newsData,
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
        ?float $r1m, ?float $r5m, ?float $stoch, float $atr,
        ?float $e12, ?float $e20, ?float $e50,
        ?array $bb, ?array $macd, ?array $piv,
        string $trend1h, array $sess, array $swing, array $cfg,
        ?array $adx = null, ?array $divergence = null, ?array $fib = null
    ): string {
        $dir  = $sig['direction'];
        $dirAr= match($dir) { 'BUY' => 'شراء 📈', 'SELL' => 'بيع 📉', default => 'انتظار ⏳' };
        $tags = implode(', ', $sig['tags'] ?? []);
        $warn = implode(' | ', $sig['warnings'] ?? []);
        $label= $cfg['label'];

        // null guards — كل القيم المُحتمَل أن تكون null
        $r1mTxt   = $r1m   !== null ? (string)$r1m   : '—';
        $r5mTxt   = $r5m   !== null ? (string)$r5m   : '—';
        $stochTxt = $stoch !== null ? (string)$stoch : '—';
        $e12Txt   = $e12   !== null ? (string)$e12   : '—';
        $e20Txt   = $e20   !== null ? (string)$e20   : '—';
        $e50Txt   = $e50   !== null ? (string)$e50   : '—';
        $macdHist = isset($macd['hist'])  ? (string)$macd['hist']  : '—';
        $bbPct    = isset($bb['pct_b'])   ? (string)$bb['pct_b']   : '—';
        $pivS1    = isset($piv['S1'])     ? (string)$piv['S1']     : '—';
        $pivP     = isset($piv['P'])      ? (string)$piv['P']      : '—';
        $pivR1    = isset($piv['R1'])     ? (string)$piv['R1']     : '—';

        // المؤشرات الجديدة
        $adxTxt = isset($adx['adx']) ? "{$adx['adx']} ({$adx['strength']})" : '—';
        $divTxt = $divergence['type'] ?? 'NONE';
        $fibTxt = '—';
        if ($fib && isset($fib['closest'])) {
            $fibTxt = "{$fib['closest']['ratio']}@{$fib['closest']['price']} ({$fib['direction']})";
        }

        if ($dir !== 'WAIT' && $plan) {
            $prompt = <<<TXT
محلل {$label} | الجلسة: {$sess['label']} | 1h: {$trend1h}
السعر: {$price} | {$dirAr} | قوة: {$sig['strength']}/10
Entry: {$plan['entry']} | SL: {$plan['sl']} (-{$plan['sl_dist']}) | TP1: {$plan['tp1']} | TP2: {$plan['tp2']} | TP3: {$plan['tp3']}
RR={$plan['rr_tp1']} | Lot={$plan['lot']} | خسارة=\${$plan['risk_usd']} | ربح TP1=\${$plan['profit_tp1']}
RSI 1m/5m: {$r1mTxt}/{$r5mTxt} | Stoch: {$stochTxt} | ATR: {$atr}
ADX: {$adxTxt} | Divergence: {$divTxt} | Fib: {$fibTxt}
MACD hist: {$macdHist} | BB%B: {$bbPct} | EMA12/20/50: {$e12Txt}/{$e20Txt}/{$e50Txt}
Swing H/L: {$swing['high']}/{$swing['low']} | الإشارات: {$tags}
{$warn}

اكتب تحليلاً مختصر (4 أسطر فقط):
1. لماذا الإشارة {$dirAr} الآن؟ (اذكر أهم مؤشر داعم)
2. المستوى الذي يُلغي الإشارة (قبل SL)
3. متى تأخذ TP1 وتحرّك SL لـ Breakeven
4. ملاحظة عن ADX/Divergence إن وُجد
TXT;
        } else {
            $prompt = <<<TXT
{$label} | انتظار | السعر: {$price} | 1h: {$trend1h} | {$sess['label']}
RSI 1m/5m: {$r1mTxt}/{$r5mTxt} | Stoch: {$stochTxt} | ATR: {$atr}
ADX: {$adxTxt} | Divergence: {$divTxt} | Fib: {$fibTxt}
MACD hist: {$macdHist} | BB%B: {$bbPct}
EMA12/20/50: {$e12Txt}/{$e20Txt}/{$e50Txt}
Pivots: S1={$pivS1} P={$pivP} R1={$pivR1}
نقاط شراء: {$sig['bull']} | نقاط بيع: {$sig['bear']} | المطلوب: {$sig['threshold']}
السبب: {$sig['reason']}

3 أسطر فقط:
1. لماذا الانتظار؟ (اذكر السبب الأقوى — ADX/News/Threshold)
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
