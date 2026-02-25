<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property float $current_price
 * @property string $recommendation
 * @property float $entry_price
 * @property float $tp1
 * @property float $tp2
 * @property float $tp3
 * @property float $stop_loss
 * @property float $risk_percent
 * @property string $direction (BUY/SELL/WAIT)
 * @property float $signal_strength
 * @property string $status (pending/closed/missed)
 * @property float|null $exit_price
 * @property string|null $exit_reason
 * @property float|null $profit_loss
 * @property float|null $pips_gained
 * @property string $trade_plan_json
 * @property array $indicators_json
 * @property \Illuminate\Support\Carbon $analyzed_at
 * @property \Illuminate\Support\Carbon|null $closed_at
 */
class GoldAnalysis extends Model
{
    use HasFactory;

    protected $table = 'gold_analyses';
    protected $fillable = [
        'current_price',
        'recommendation',
        'entry_price',
        'tp1',
        'tp2',
        'tp3',
        'stop_loss',
        'risk_percent',
        'direction',
        'signal_strength',
        'status',
        'exit_price',
        'exit_reason',
        'profit_loss',
        'pips_gained',
        'trade_plan_json',
        'indicators_json',
        'analyzed_at',
        'closed_at',
    ];

    protected $casts = [
        'current_price' => 'float',
        'entry_price' => 'float',
        'tp1' => 'float',
        'tp2' => 'float',
        'tp3' => 'float',
        'stop_loss' => 'float',
        'risk_percent' => 'float',
        'signal_strength' => 'float',
        'exit_price' => 'float',
        'profit_loss' => 'float',
        'pips_gained' => 'float',
        'trade_plan_json' => 'json',
        'indicators_json' => 'json',
        'analyzed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    /**
     * حساب دقة التوصيات
     */
    public static function getAccuracy()
    {
        $closedTrades = self::where('status', 'closed')->count();
        if ($closedTrades == 0) return 0;
        
        $profitableTrades = self::where('status', 'closed')
            ->where('profit_loss', '>', 0)
            ->count();
        
        return round(($profitableTrades / $closedTrades) * 100, 2);
    }

    /**
     * حساب متوسط الربح
     */
    public static function getAverageProfitLoss()
    {
        $closed = self::where('status', 'closed')->get();
        if ($closed->count() == 0) return 0;
        
        return round($closed->avg('profit_loss'), 2);
    }

    /**
     * حساب نسبة الربح/الخسارة
     */
    public static function getProfitFactor()
    {
        $profit = self::where('status', 'closed')
            ->where('profit_loss', '>', 0)
            ->sum('profit_loss');
        
        $loss = abs(self::where('status', 'closed')
            ->where('profit_loss', '<', 0)
            ->sum('profit_loss'));
        
        return $loss == 0 ? 0 : round($profit / $loss, 2);
    }
}
