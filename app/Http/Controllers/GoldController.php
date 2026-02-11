<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class GoldController extends Controller
{
    public function landing()
    {
        return view('landing');
    }

    // --- Technical Indicators Logic ---

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
            $gains[] = max(0, $diff);
            $losses[] = max(0, -$diff);
        }
        $avgGain = array_sum(array_slice($gains, 0, $period)) / $period;
        $avgLoss = array_sum(array_slice($losses, 0, $period)) / $period;
        if ($avgLoss == 0) return 100;
        for ($i = $period; $i < count($gains); $i++) {
            $avgGain = (($avgGain * ($period - 1)) + $gains[$i]) / $period;
            $avgLoss = (($avgLoss * ($period - 1)) + $losses[$i]) / $period;
        }
        $rs = $avgGain / $avgLoss;
        return round(100 - (100 / (1 + $rs)), 2);
    }

    private function calculateMACD($prices)
    {
        if (count($prices) < 26) return null;
        $ema12 = $this->calculateEMA($prices, 12);
        $ema26 = $this->calculateEMA($prices, 26);
        $macdLine = $ema12 - $ema26;
        return round($macdLine, 3);
    }

    private function calculatePivotPoints($lastCandle)
    {
        $high = (float)$lastCandle['high'];
        $low = (float)$lastCandle['low'];
        $close = (float)$lastCandle['close'];

        $pivot = ($high + $low + $close) / 3;
        $r1 = (2 * $pivot) - $low;
        $s1 = (2 * $pivot) - $high;
        $r2 = $pivot + ($high - $low);
        $s2 = $pivot - ($high - $low);

        return [
            'pivot' => round($pivot, 2),
            'R1' => round($r1, 2),
            'S1' => round($s1, 2),
            'R2' => round($r2, 2),
            'S2' => round($s2, 2),
        ];
    }

    public function getAnalysis(Request $request)
    {
        Log::info('Gold Analysis: Starting Professional Scalping Analysis with Groq...');

        $twelveDataKey = env('TWELVEDATA_API_KEY');
        $groqKey = env('GROQ_API_KEY');

        if (empty($twelveDataKey) || empty($groqKey)) {
            Log::error('Gold Analysis: Missing API Keys (TwelveData or Groq)');
            return response()->json(['error' => 'API keys are not configured in .env'], 500);
        }

        try {
            // 1. Fetch 5min Data
            $res5m = Http::get("https://api.twelvedata.com/time_series?symbol=XAU/USD&interval=5min&outputsize=100&apikey={$twelveDataKey}")->json();

            // 2. Fetch 1h Data
            $res1h = Http::get("https://api.twelvedata.com/time_series?symbol=XAU/USD&interval=1h&outputsize=10&apikey={$twelveDataKey}")->json();

            if (!isset($res5m['values']) || !isset($res1h['values'])) {
                Log::error('Gold Analysis: Market Data Error', ['5m' => $res5m, '1h' => $res1h]);
                return response()->json(['error' => 'Failed to fetch market data'], 500);
            }

            // Process 5min Data
            $candles5m = array_reverse($res5m['values']);
            $prices5m = array_map(fn($c) => (float)$c['close'], $candles5m);
            $currentPrice = end($prices5m);

            $ema20 = $this->calculateEMA($prices5m, 20);
            $ema50 = $this->calculateEMA($prices5m, 50);
            $rsi = $this->calculateRSI($prices5m, 14);
            $macd = $this->calculateMACD($prices5m);
            $pivots = $this->calculatePivotPoints($res5m['values'][0]);

            // Process 1h Data (Trend)
            $last1hClose = (float)$res1h['values'][0]['close'];
            $prev1hClose = (float)$res1h['values'][1]['close'];
            $trend1h = $last1hClose > $prev1hClose ? 'صاعد (Bullish)' : 'هابط (Bearish)';

            // --- PROMPT مخصص للسكالبينج مع JSON منظم ---
            $prompt = "
أنت خبير تداول محترف (Senior Trader) متخصص في **سكالبينج الذهب (XAU/USD)** بحساب صغير 100$.
قم بتحليل البيانات التالية بدقة عالية لتقديم توصية سكالبينج احترافية بأهداف ربح سريعة وصغيرة، ووقف خسارة واقعي جداً (لا يتجاوز 15 نقطة).

**المطلوب:**
1. تحليل فني عميق: ادمج المؤشرات مع مستويات الدعم/المقاومة والاتجاه العام.
2. القرار النهائي: (شراء / بيع / انتظار).
3. استراتيجية الدخول: **منطقة دخول محددة** (سعر محدد، مثل اختراق المقاومة أو ارتداد من الدعم).
4. إدارة الأهداف: حدد **3 أهداف سعرية صغيرة** (المسافة بين الهدف والآخر 3-5 نقاط تقريباً).
5. حماية الحساب: **وقف خسارة ضيق ومنطقي** (SL لا يزيد عن 10-15 نقطة من سعر الدخول).
6. إدارة مخاطر لحساب 100$: حجم اللوت = **0.01 فقط**، نسبة المخاطرة 1% أو 2% كحد أقصى، مع نصيحة.

**بعد الانتهاء من التحليل، أضف بالأسفل فقط كائن JSON** (بدون أي نصوص إضافية بعده) بالصيغة التالية:
{
  \"entry_zone\": 5073.44,
  \"tp1\": 5076.51,
  \"tp2\": 5080.00,
  \"tp3\": 5083.50,
  \"sl\": 5064.21,
  \"lot_size\": 0.01,
  \"risk_percent\": 1.5
}

- استخدم أرقاماً عشرية حقيقية بناءً على التحليل.
- تأكد من تناسق القيم مع التحليل الذي كتبته.
- اكتب الـ JSON في سطر منفصل في نهاية الرد.

**البيانات الفنية:**
- الاتجاه العام (فريم ساعة): {$trend1h}
- سعر إغلاق الساعة الأخيرة: {$last1hClose}
- السعر الحالي (5 دقائق): {$currentPrice}
- مؤشر EMA 20: {$ema20}
- مؤشر EMA 50: {$ema50}
- مؤشر RSI (14): {$rsi}
- مؤشر MACD: {$macd}
- مستويات الدعم والمقاومة (Pivot Points):
  - الارتكاز: {$pivots['pivot']}
  - المقاومة 1: {$pivots['R1']} | المقاومة 2: {$pivots['R2']}
  - الدعم 1: {$pivots['S1']} | الدعم 2: {$pivots['S2']}
";

            Log::info('Gold Analysis: Sending Professional Scalping Prompt to Groq...');

            // Groq API (متوافقة مع OpenAI)
            $responseAI = Http::withHeaders([
                'Authorization' => 'Bearer ' . $groqKey,
                'Content-Type' => 'application/json'
            ])->post('https://api.groq.com/openai/v1/chat/completions', [
                "model" => "llama-3.3-70b-versatile",
                "messages" => [
                    ["role" => "system", "content" => "أنت كبير متداولي الذهب، تعطي توصيات سكالبينج صارمة مع إدارة مخاطر حادة لحساب 100$."],
                    ["role" => "user", "content" => $prompt]
                ],
                "temperature" => 0.2,
                "max_tokens" => 1500
            ]);

            if (!$responseAI->successful()) {
                Log::error('Gold Analysis: Groq API Failed', ['res' => $responseAI->json()]);
                return response()->json(['error' => 'AI Analysis failed (Groq Error)'], 500);
            }

            $dataAI = $responseAI->json();
            $rawRecommendation = $dataAI['choices'][0]['message']['content'] ?? 'فشل توليد التوصية';
            $tradePlan = null;

            // استخراج JSON من النص
            preg_match('/\{(?:[^{}]|(?R))*\}/s', $rawRecommendation, $jsonMatch);
            if (isset($jsonMatch[0])) {
                $decoded = json_decode($jsonMatch[0], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $tradePlan = $decoded;
                    // إزالة JSON من التوصية المعروضة للمستخدم
                    $recommendation = trim(str_replace($jsonMatch[0], '', $rawRecommendation));
                } else {
                    $recommendation = $rawRecommendation;
                }
            } else {
                $recommendation = $rawRecommendation;
            }

            Log::info('Gold Analysis: Success with Groq!', ['tradePlan' => $tradePlan]);

            return response()->json([
                'current_price' => $currentPrice,
                'ema20' => $ema20,
                'ema50' => $ema50,
                'rsi' => $rsi,
                'macd' => $macd,
                'pivots' => $pivots,
                'trend1h' => $trend1h,
                'recommendation' => $recommendation,
                'trade_plan' => $tradePlan,
                'time' => now()->format('H:i:s'),
                'chart_data' => array_slice($prices5m, -20)
            ]);

        } catch (\Exception $e) {
            Log::error('Gold Analysis: Exception', ['msg' => $e->getMessage()]);
            return response()->json(['error' => 'Internal server error'], 500);
        }
    }
}
