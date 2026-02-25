<?php

namespace App\Http\Controllers;

use App\Models\GoldAnalysis;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TradeHistoryController extends Controller
{
    /**
     * حفظ التحليل في قاعدة البيانات
     */
    public function saveAnalysis(Request $request)
    {
        try {
            $validated = $request->validate([
                'current_price' => 'required|numeric',
                'recommendation' => 'required|string',
                'entry_price' => 'numeric|nullable',
                'tp1' => 'numeric|nullable',
                'tp2' => 'numeric|nullable',
                'tp3' => 'numeric|nullable',
                'stop_loss' => 'numeric|nullable',
                'direction' => 'required|in:BUY,SELL,WAIT',
                'signal_strength' => 'numeric|between:0,10',
                'trade_plan_json' => 'json|nullable',
                'indicators_json' => 'json|nullable',
            ]);

            $analysis = GoldAnalysis::create([
                ...$validated,
                'status' => 'pending',
                'analyzed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'analysis_id' => $analysis->id,
                'message' => 'تم حفظ التحليل بنجاح',
            ]);
        } catch (\Exception $e) {
            Log::error('Save Analysis Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * إغلاق التداول وحساب النتيجة
     */
    public function closeTrade(Request $request, $analysisId)
    {
        try {
            $validated = $request->validate([
                'exit_price' => 'required|numeric',
                'exit_reason' => 'required|string',
            ]);

            $analysis = GoldAnalysis::findOrFail($analysisId);
            
            if ($analysis->status === 'closed') {
                return response()->json(['error' => 'التداول مغلق بالفعل'], 400);
            }

            // حساب الربح/الخسارة
            $priceDifference = $validated['exit_price'] - $analysis->entry_price;
            $pips = abs($priceDifference * 100); // تحويل لنقاط
            
            // حساب الربح/الخسارة بالدولار (مبسط)
            // 1 لوت = 100,000 وحدة، لكننا نستخدم 0.01 لوت = 1000 وحدة
            // 1 نقطة = 0.0001، عند 0.01 لوت = $1 لكل نقطة
            $profitLoss = $priceDifference * 1000 * 0.01;

            $analysis->update([
                'exit_price' => $validated['exit_price'],
                'exit_reason' => $validated['exit_reason'],
                'profit_loss' => $profitLoss,
                'pips_gained' => $priceDifference < 0 ? -$pips : $pips,
                'status' => 'closed',
                'closed_at' => now(),
            ]);

            return response()->json([
                'success' => true,
                'profit_loss' => $profitLoss,
                'pips_gained' => $pips,
                'message' => 'تم إغلاق التداول',
            ]);
        } catch (\Exception $e) {
            Log::error('Close Trade Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /**
     * الحصول على الإحصائيات
     */
    public function getStatistics()
    {
        try {
            $totalAnalyses = GoldAnalysis::count();
            $closedTrades = GoldAnalysis::where('status', 'closed')->count();
            $pendingTrades = GoldAnalysis::where('status', 'pending')->count();
            $openTrades = GoldAnalysis::where('status', 'open')->count();

            $accuracy = GoldAnalysis::getAccuracy();
            $avgProfitLoss = GoldAnalysis::getAverageProfitLoss();
            $profitFactor = GoldAnalysis::getProfitFactor();

            $totalProfit = GoldAnalysis::where('status', 'closed')
                ->where('profit_loss', '>', 0)
                ->sum('profit_loss');

            $totalLoss = abs(GoldAnalysis::where('status', 'closed')
                ->where('profit_loss', '<', 0)
                ->sum('profit_loss'));

            $totalPips = GoldAnalysis::where('status', 'closed')
                ->sum('pips_gained');

            return response()->json([
                'statistics' => [
                    'total_analyses' => $totalAnalyses,
                    'closed_trades' => $closedTrades,
                    'pending_trades' => $pendingTrades,
                    'open_trades' => $openTrades,
                    'win_rate' => $accuracy . '%',
                    'avg_profit_loss' => $avgProfitLoss,
                    'profit_factor' => $profitFactor,
                    'total_profit' => round($totalProfit, 2),
                    'total_loss' => round($totalLoss, 2),
                    'net_profit' => round($totalProfit - $totalLoss, 2),
                    'total_pips' => round($totalPips, 2),
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Get Statistics Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * الحصول على التحليلات الأخيرة
     */
    public function getRecentAnalyses($limit = 10)
    {
        try {
            $analyses = GoldAnalysis::orderBy('analyzed_at', 'desc')
                ->limit($limit)
                ->get()
                ->map(function ($analysis) {
                    return [
                        'id' => $analysis->id,
                        'price' => $analysis->current_price,
                        'direction' => $analysis->direction,
                        'signal_strength' => $analysis->signal_strength,
                        'entry' => $analysis->entry_price,
                        'tp1' => $analysis->tp1,
                        'tp2' => $analysis->tp2,
                        'tp3' => $analysis->tp3,
                        'sl' => $analysis->stop_loss,
                        'status' => $analysis->status,
                        'profit_loss' => $analysis->profit_loss,
                        'analyzed_at' => $analysis->analyzed_at->format('Y-m-d H:i:s'),
                    ];
                });

            return response()->json(['data' => $analyses]);
        } catch (\Exception $e) {
            Log::error('Get Recent Analyses Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * تحليل الأداء حسب الاتجاه
     */
    public function performanceByDirection()
    {
        try {
            $directions = ['BUY', 'SELL'];
            $performance = [];

            foreach ($directions as $dir) {
                $trades = GoldAnalysis::where('direction', $dir)
                    ->where('status', 'closed')
                    ->get();

                if ($trades->count() > 0) {
                    $wins = $trades->where('profit_loss', '>', 0)->count();
                    $performance[$dir] = [
                        'total_trades' => $trades->count(),
                        'winning_trades' => $wins,
                        'win_rate' => round(($wins / $trades->count()) * 100, 2) . '%',
                        'total_profit' => round($trades->sum('profit_loss'), 2),
                        'avg_profit' => round($trades->avg('profit_loss'), 2),
                        'avg_pips' => round($trades->avg('pips_gained'), 2),
                    ];
                }
            }

            return response()->json(['performance' => $performance]);
        } catch (\Exception $e) {
            Log::error('Performance Analysis Error: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}
