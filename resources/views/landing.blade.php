<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gold AI Scalper | محلل الذهب بالسكالبينج</title>

    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>

    <link href="https://fonts.googleapis.com/css2?family=Tajawal:wght@400;700&display=swap" rel="stylesheet">

    <style>
        body {
            font-family: 'Tajawal', sans-serif;
            background: #0f172a;
            background-image:
                radial-gradient(at 0% 0%, rgba(30, 58, 138, 0.5) 0, transparent 50%),
                radial-gradient(at 100% 100%, rgba(88, 28, 135, 0.5) 0, transparent 50%);
        }
        .glass-card {
            background: rgba(255, 255, 255, 0.03);
            backdrop-filter: blur(12px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }
        .gold-text {
            background: linear-gradient(to right, #fde047, #eab308, #fde047);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .loading-spinner {
            border: 3px solid rgba(255, 255, 255, 0.1);
            border-radius: 50%;
            border-top: 3px solid #eab308;
            width: 24px;
            height: 24px;
            animation: spin 1s linear infinite;
        }
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        /* Markdown Styling */
        #recommendation h1, #recommendation h2, #recommendation h3 { color: #fde047; font-weight: bold; margin-top: 1rem; margin-bottom: 0.5rem; }
        #recommendation p { margin-bottom: 0.75rem; color: #e2e8f0; }
        #recommendation ul { list-style-type: disc; padding-right: 1.5rem; margin-bottom: 1rem; }
        #recommendation li { margin-bottom: 0.25rem; }
        #recommendation strong { color: #fbbf24; }

        /* Signal Badge Styles */
        .signal-buy { background: linear-gradient(135deg, #22c55e, #16a34a); }
        .signal-sell { background: linear-gradient(135deg, #ef4444, #dc2626); }
        .signal-wait { background: linear-gradient(135deg, #64748b, #475569); }

        .strength-bar { transition: width 0.5s ease-out; }
    </style>
</head>

<body class="min-h-screen text-slate-200 p-4 md:p-8">

<div class="max-w-7xl mx-auto space-y-6">

    <!-- Header -->
    <header class="text-center space-y-4">
        <h1 class="text-4xl md:text-6xl font-bold gold-text">Gold AI Scalper</h1>
        <p class="text-slate-400 text-lg">تحليل سكالبينج احترافي للذهب XAU/USD – مخصص لحساب 100$</p>
        <div class="inline-flex items-center px-4 py-1 rounded-full bg-yellow-500/10 border border-yellow-500/20 text-yellow-500 text-sm">
            <span class="w-2 h-2 bg-yellow-500 rounded-full ml-2 animate-pulse"></span>
            🎯 سكالبينج | لوت 0.01 | مخاطرة 1.5%
        </div>
    </header>

    <!-- Signal Indicator (Shows direction) -->
    <div id="signalBanner" class="hidden glass-card p-4 rounded-2xl">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <span id="signalIcon" class="text-2xl">⚡</span>
                <div>
                    <span id="signalDirection" class="text-lg font-bold text-white">--</span>
                    <span class="text-slate-400 mr-2">| قوة الإشارة:</span>
                    <span id="signalStrength" class="text-yellow-400 font-bold">--/10</span>
                </div>
            </div>
            <div class="w-32 bg-slate-700 rounded-full h-2">
                <div id="strengthBar" class="strength-bar h-2 rounded-full bg-yellow-500" style="width: 0%"></div>
            </div>
        </div>
    </div>

    <!-- ✅ WIDGET: خطة التداول السريعة -->
    <div id="tradePlanWidget" class="hidden">
        <div class="glass-card p-4 rounded-2xl">
            <div class="flex items-center gap-2 mb-3">
                <div class="p-1.5 bg-green-500/20 rounded-lg">
                    <svg class="w-5 h-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-5m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                    </svg>
                </div>
                <h3 class="text-lg font-bold text-white">📊 خطة التداول - تنفيذ فوري</h3>
                <span class="text-xs bg-yellow-500/20 text-yellow-500 px-2 py-1 rounded-full mr-auto">100$ Account</span>
            </div>
            <div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-3 text-sm">
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">منطقة الدخول</div>
                    <div id="planEntry" class="font-mono text-lg font-bold text-yellow-400">---</div>
                </div>
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">TP1</div>
                    <div id="planTp1" class="font-mono text-green-400 font-bold">---</div>
                </div>
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">TP2</div>
                    <div id="planTp2" class="font-mono text-green-400 font-bold">---</div>
                </div>
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">TP3</div>
                    <div id="planTp3" class="font-mono text-green-400 font-bold">---</div>
                </div>
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">وقف خسارة</div>
                    <div id="planSl" class="font-mono text-red-400 font-bold">---</div>
                </div>
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">حجم اللوت</div>
                    <div id="planLot" class="font-mono text-blue-400 font-bold">0.01</div>
                </div>
                <div class="bg-white/5 p-3 rounded-xl">
                    <div class="text-slate-400 text-xs mb-1">المخاطرة</div>
                    <div id="planRisk" class="font-mono text-orange-400 font-bold">1.5%</div>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Dashboard -->
    <div class="grid grid-cols-1 lg:grid-cols-4 gap-6">

        <!-- Stats Sidebar -->
        <div class="space-y-4">
            <!-- Price Card -->
            <div class="glass-card p-5 rounded-2xl">
                <h3 class="text-slate-400 text-sm mb-1">السعر الحالي XAU/USD</h3>
                <div class="flex items-baseline gap-2">
                    <span id="price" class="text-4xl font-bold text-white">----.--</span>
                    <span class="text-slate-500">USD</span>
                </div>
                <div class="flex items-center gap-2 mt-2 text-sm">
                    <span class="text-slate-400">الاتجاه:</span>
                    <span id="trendShort" class="font-bold text-cyan-400">--</span>
                </div>
            </div>

            <!-- EMA Indicators -->
            <div class="glass-card p-5 rounded-2xl space-y-3">
                <h3 class="text-slate-400 text-sm border-b border-white/10 pb-2">📈 المتوسطات المتحركة (EMA)</h3>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">EMA 12</span>
                    <span id="ema12" class="font-mono text-cyan-400">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">EMA 20</span>
                    <span id="ema20" class="font-mono text-blue-400">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">EMA 50</span>
                    <span id="ema50" class="font-mono text-purple-400">--</span>
                </div>
            </div>

            <!-- Oscillators -->
            <div class="glass-card p-5 rounded-2xl space-y-3">
                <h3 class="text-slate-400 text-sm border-b border-white/10 pb-2">📊 مؤشرات التذبذب</h3>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">RSI (1m)</span>
                    <span id="rsi1m" class="font-mono text-yellow-500">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">RSI (5m)</span>
                    <span id="rsi5m" class="font-mono text-yellow-500">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">Stochastic</span>
                    <span id="stochastic" class="font-mono text-pink-400">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">MACD</span>
                    <span id="macd" class="font-mono text-indigo-400">--</span>
                </div>
            </div>

            <!-- Volatility & Pivots -->
            <div class="glass-card p-5 rounded-2xl space-y-3">
                <h3 class="text-slate-400 text-sm border-b border-white/10 pb-2">📉 التقلب والمستويات</h3>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">ATR (14)</span>
                    <span id="atr" class="font-mono text-orange-400">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">BB Upper</span>
                    <span id="bbUpper" class="font-mono text-red-400">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">BB Middle</span>
                    <span id="bbMiddle" class="font-mono text-slate-300">--</span>
                </div>
                <div class="flex justify-between items-center">
                    <span class="text-slate-300">BB Lower</span>
                    <span id="bbLower" class="font-mono text-green-400">--</span>
                </div>
                <div class="border-t border-white/10 pt-2 mt-2">
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400">Pivot</span>
                        <span id="pivot" class="font-mono text-white">--</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400">R1</span>
                        <span id="r1" class="font-mono text-red-400">--</span>
                    </div>
                    <div class="flex justify-between items-center text-xs">
                        <span class="text-slate-400">S1</span>
                        <span id="s1" class="font-mono text-green-400">--</span>
                    </div>
                </div>
            </div>

            <!-- Action Button -->
            <button id="analyzeBtn" class="w-full py-4 bg-gradient-to-r from-yellow-600 to-yellow-500 hover:from-yellow-500 hover:to-yellow-400 text-black font-bold rounded-2xl transition-all transform hover:scale-[1.02] active:scale-95 shadow-lg shadow-yellow-600/20 flex items-center justify-center gap-3">
                <span id="btnText">تحديث التحليل الآن</span>
                <div id="btnLoader" class="loading-spinner hidden"></div>
            </button>

            <p class="text-center text-xs text-slate-500">آخر تحديث: <span id="time">--:--:--</span></p>
        </div>

        <!-- Analysis & Chart Area -->
        <div class="lg:col-span-3 space-y-4">
            <!-- Chart -->
            <div class="glass-card p-4 rounded-2xl h-[250px]">
                <canvas id="priceChart"></canvas>
            </div>

            <!-- AI Recommendation -->
            <div class="glass-card p-6 rounded-2xl min-h-[350px]">
                <div class="flex items-center gap-3 mb-4 border-b border-white/10 pb-3">
                    <div class="p-2 bg-yellow-500/20 rounded-lg">
                        <svg class="w-6 h-6 text-yellow-500" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"></path></svg>
                    </div>
                    <h2 class="text-xl font-bold text-white">توصية المحلل الذكي</h2>
                </div>
                <div id="recommendation" class="text-slate-300 leading-relaxed">
                    <div class="flex flex-col items-center justify-center py-16 text-slate-500">
                        <svg class="w-12 h-12 mb-4 opacity-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                        <p>اضغط على الزر الجانبي لبدء التحليل</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center text-slate-500 text-sm pb-6">
        <p>© 2026 Gold AI Scalper - جميع الحقوق محفوظة</p>
        <p class="mt-1 text-xs">⚠️ تنبيه: التداول ينطوي على مخاطر عالية. النتائج السابقة ليست ضمانة للمستقبل.</p>
    </footer>
</div>

<script>
    let chart;
    const analyzeBtn = document.getElementById('analyzeBtn');
    const btnText = document.getElementById('btnText');
    const btnLoader = document.getElementById('btnLoader');
    const recommendationDiv = document.getElementById('recommendation');

    // تهيئة الرسم البياني
    function initChart() {
        const ctx = document.getElementById('priceChart').getContext('2d');
        chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: Array(20).fill(''),
                datasets: [{
                    label: 'سعر الذهب',
                    data: [],
                    borderColor: '#eab308',
                    backgroundColor: 'rgba(234, 179, 8, 0.1)',
                    borderWidth: 2,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 2,
                    pointBackgroundColor: '#eab308'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode: 'index',
                        intersect: false,
                        backgroundColor: 'rgba(0,0,0,0.8)',
                        titleColor: '#fbbf24',
                        bodyColor: '#fff',
                        borderColor: 'rgba(255,255,255,0.1)',
                        borderWidth: 1
                    }
                },
                scales: {
                    x: { display: true, grid: { color: 'rgba(255,255,255,0.05)' } },
                    y: {
                        grid: { color: 'rgba(255, 255, 255, 0.05)' },
                        ticks: { color: '#64748b', font: { size: 10 } }
                    }
                },
                interaction: {
                    mode: 'nearest',
                    axis: 'x',
                    intersect: false
                }
            }
        });
    }

    // تحديث مؤشر الإشارة
    function updateSignalBanner(signal) {
        const banner = document.getElementById('signalBanner');
        const direction = document.getElementById('signalDirection');
        const strength = document.getElementById('signalStrength');
        const icon = document.getElementById('signalIcon');
        const bar = document.getElementById('strengthBar');

        if (!signal || signal.direction === 'WAIT') {
            banner.classList.add('hidden');
            return;
        }

        banner.classList.remove('hidden');
        direction.innerText = signal.direction === 'BUY' ? '🟢 شراء' : '🔴 بيع';
        strength.innerText = signal.strength + '/10';
        icon.innerText = signal.direction === 'BUY' ? '📈' : '📉';

        //强度的颜色和宽度
        const percentage = (signal.strength / 10) * 100;
        bar.style.width = percentage + '%';

        if (signal.direction === 'BUY') {
            bar.classList.remove('bg-red-500');
            bar.classList.add('bg-green-500');
        } else {
            bar.classList.remove('bg-green-500');
            bar.classList.add('bg-red-500');
        }
    }

    // تحديث ويدجت خطة التداول
    function updateTradePlanWidget(plan) {
        const widget = document.getElementById('tradePlanWidget');

        if (plan) {
            document.getElementById('planEntry').innerText = plan.entry?.toFixed(2) || plan.entry_zone?.toFixed(2) || '---';
            document.getElementById('planTp1').innerText = plan.tp1?.toFixed(2) || '---';
            document.getElementById('planTp2').innerText = plan.tp2?.toFixed(2) || '---';
            document.getElementById('planTp3').innerText = plan.tp3?.toFixed(2) || '---';
            document.getElementById('planSl').innerText = plan.sl?.toFixed(2) || '---';
            document.getElementById('planLot').innerText = plan.lot_size || '0.01';
            document.getElementById('planRisk').innerText = (plan.risk_percent || '1.5') + '%';
            widget.classList.remove('hidden');
        } else {
            widget.classList.add('hidden');
        }
    }

    // تحديث المؤشرات على الواجهة
    function updateIndicators(data) {
        // Price & Trend
        document.getElementById('price').innerText = data.current_price?.toFixed(2) || '---';
        document.getElementById('trendShort').innerText = data.trend_short || '--';

        // EMA
        if (data.indicators?.ema) {
            document.getElementById('ema12').innerText = data.indicators.ema.ema12?.toFixed(2) || '--';
            document.getElementById('ema20').innerText = data.indicators.ema.ema20?.toFixed(2) || '--';
            document.getElementById('ema50').innerText = data.indicators.ema.ema50?.toFixed(2) || '--';
        }

        // RSI
        if (data.indicators?.rsi) {
            document.getElementById('rsi1m').innerText = data.indicators.rsi.rsi_1m || '--';
            document.getElementById('rsi5m').innerText = data.indicators.rsi.rsi_5m || '--';
        }

        // Stochastic & MACD
        document.getElementById('stochastic').innerText = data.indicators?.stochastic || '--';
        document.getElementById('macd').innerText = data.indicators?.macd || '--';

        // ATR
        document.getElementById('atr').innerText = data.indicators?.atr || '--';

        // Bollinger Bands
        if (data.indicators?.bb) {
            document.getElementById('bbUpper').innerText = data.indicators.bb.upper?.toFixed(2) || '--';
            document.getElementById('bbMiddle').innerText = data.indicators.bb.middle?.toFixed(2) || '--';
            document.getElementById('bbLower').innerText = data.indicators.bb.lower?.toFixed(2) || '--';
        }

        // Pivots
        if (data.indicators?.pivots) {
            document.getElementById('pivot').innerText = data.indicators.pivots.pivot?.toFixed(2) || '--';
            document.getElementById('r1').innerText = data.indicators.pivots.R1?.toFixed(2) || '--';
            document.getElementById('s1').innerText = data.indicators.pivots.S1?.toFixed(2) || '--';
        }

        // Time
        document.getElementById('time').innerText = data.time || '--:--:--';
    }

    // جلب التحليل
    async function fetchAnalysis() {
        analyzeBtn.disabled = true;
        btnText.innerText = "جاري التحليل...";
        btnLoader.classList.remove('hidden');
        recommendationDiv.style.opacity = '0.5';

        try {
            const response = await axios.get('/api/get-analysis');
            const data = response.data;

            // التحقق من وجود خطأ
            if (data.error) {
                throw new Error(data.error);
            }

            // تحديث المؤشرات
            updateIndicators(data);

            // تحديث_signal Banner
            updateSignalBanner(data.trading_signal);

            // تحديث التوصية
            if (data.recommendation) {
                recommendationDiv.innerHTML = marked.parse(data.recommendation);
            } else {
                recommendationDiv.innerHTML = '<div class="text-yellow-400 p-4 bg-yellow-400/10 rounded-xl border border-yellow-400/20">⏳ جاري انتظار إشارة واضحة للدخول...</div>';
            }
            recommendationDiv.style.opacity = '1';

            // تحديث خطة التداول
            if (data.trade_plan) {
                updateTradePlanWidget(data.trade_plan);
            } else if (data.trade_setup) {
                updateTradePlanWidget(data.trade_setup);
            } else {
                document.getElementById('tradePlanWidget').classList.add('hidden');
            }

            // تحديث الرسم البياني
            const chartData = data.chart_data_5m || data.chart_data || [];
            chart.data.datasets[0].data = chartData;
            chart.data.labels = chartData.map((_, i) => i);
            chart.update();

        } catch (error) {
            console.error(error);
            let errorMsg = '❌ حدث خطأ';
            if (error.response?.data?.error) {
                errorMsg += ': ' + error.response.data.error;
            } else if (error.message) {
                errorMsg += ': ' + error.message;
            }
            recommendationDiv.innerHTML = `<div class="text-red-400 p-4 bg-red-400/10 rounded-xl border border-red-400/20">${errorMsg}</div>`;
            recommendationDiv.style.opacity = '1';
        } finally {
            analyzeBtn.disabled = false;
            btnText.innerText = "تحديث التحليل الآن";
            btnLoader.classList.add('hidden');
        }
    }

    // Event Listeners
    analyzeBtn.addEventListener('click', fetchAnalysis);

    // التشغيل التلقائي
    window.onload = () => {
        initChart();
    };
</script>

</body>
</html>
