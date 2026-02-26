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

    <!-- ✅ Signal Type Badge -->
    <div id="signalTypeBadge" class="hidden glass-card p-6 rounded-2xl text-center">
        <div class="flex flex-col items-center gap-3">
            <span id="signalBadgeIcon" class="text-5xl">⏳</span>
            <div>
                <div id="signalBadgeText" class="text-3xl font-bold text-slate-400">انتظار</div>
                <div class="text-slate-400 mt-1">لا توصية واضحة حالياً</div>
            </div>
        </div>
    </div>

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
        <div class="glass-card p-5 rounded-2xl">
            <div class="flex items-center gap-2 mb-4">
                <div class="p-2 bg-gradient-to-r from-yellow-500/20 to-orange-500/20 rounded-xl border border-yellow-500/30">
                    <svg class="w-6 h-6 text-yellow-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                    </svg>
                </div>
                <h3 class="text-xl font-bold text-white">📊 خطة التداول الموصى بها</h3>
                <span id="planSignalType" class="mr-auto px-3 py-1 rounded-full text-sm font-bold bg-slate-700 text-slate-300">WAIT</span>
            </div>

            <!-- Entry Zone -->
            <div class="mb-4 p-4 bg-gradient-to-r from-blue-500/10 to-cyan-500/10 rounded-xl border border-blue-500/20">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <span class="text-blue-400">🎯</span>
                        <span class="text-slate-300 font-medium">منطقة الدخول</span>
                    </div>
                    <div id="planEntry" class="text-2xl font-mono font-bold text-blue-400">---</div>
                </div>
            </div>

            <!-- Targets Grid -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-3 mb-4">
                <!-- TP1 -->
                <div class="p-4 bg-gradient-to-r from-green-500/10 to-emerald-500/10 rounded-xl border border-green-500/20">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 flex items-center justify-center bg-green-500/20 rounded-full text-green-400 font-bold text-sm">1</span>
                        <span class="text-slate-300 text-sm">الهدف الأول</span>
                    </div>
                    <div id="planTp1" class="text-xl font-mono font-bold text-green-400 text-center">---</div>
                    <div class="text-xs text-slate-500 text-center mt-1">Take Profit 1</div>
                </div>
                <!-- TP2 -->
                <div class="p-4 bg-gradient-to-r from-teal-500/10 to-green-500/10 rounded-xl border border-teal-500/20">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 flex items-center justify-center bg-teal-500/20 rounded-full text-teal-400 font-bold text-sm">2</span>
                        <span class="text-slate-300 text-sm">الهدف الثاني</span>
                    </div>
                    <div id="planTp2" class="text-xl font-mono font-bold text-teal-400 text-center">---</div>
                    <div class="text-xs text-slate-500 text-center mt-1">Take Profit 2</div>
                </div>
                <!-- TP3 -->
                <div class="p-4 bg-gradient-to-r from-emerald-500/10 to-green-500/10 rounded-xl border border-emerald-500/20">
                    <div class="flex items-center gap-2 mb-2">
                        <span class="w-8 h-8 flex items-center justify-center bg-emerald-500/20 rounded-full text-emerald-400 font-bold text-sm">3</span>
                        <span class="text-slate-300 text-sm">الهدف الثالث</span>
                    </div>
                    <div id="planTp3" class="text-xl font-mono font-bold text-emerald-400 text-center">---</div>
                    <div class="text-xs text-slate-500 text-center mt-1">Take Profit 3</div>
                </div>
            </div>

            <!-- Stop Loss & Risk Row -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 mb-4">
                <!-- Stop Loss -->
                <div class="p-4 bg-gradient-to-r from-red-500/10 to-rose-500/10 rounded-xl border border-red-500/20">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-5 h-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"></path>
                        </svg>
                        <span class="text-slate-300 font-medium">⚠️ وقف الخسارة</span>
                    </div>
                    <div id="planSl" class="text-2xl font-mono font-bold text-red-400 text-center">---</div>
                    <div class="text-xs text-slate-500 text-center mt-1">Stop Loss - إغلاق فوري عند الوصول</div>
                </div>
                <!-- Risk Info -->
                <div class="p-4 bg-gradient-to-r from-orange-500/10 to-amber-500/10 rounded-xl border border-orange-500/20">
                    <div class="flex items-center gap-2 mb-2">
                        <svg class="w-5 h-5 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"></path>
                        </svg>
                        <span class="text-slate-300 font-medium">إدارة المخاطر</span>
                    </div>
                    <div class="flex justify-between items-center">
                        <div class="text-center">
                            <div class="text-xs text-slate-400">حجم اللوت</div>
                            <div id="planLot" class="text-lg font-mono font-bold text-orange-400">0.01</div>
                        </div>
                        <div class="text-center">
                            <div class="text-xs text-slate-400">نسبة المخاطرة</div>
                            <div id="planRisk" class="text-lg font-mono font-bold text-orange-400">1.5%</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Signal Strength Bar -->
            <div class="p-3 bg-white/5 rounded-xl">
                <div class="flex items-center justify-between mb-2">
                    <span class="text-slate-400 text-sm">قوة الإشارة</span>
                    <span id="planSignalStrength" class="text-yellow-400 font-bold">--/10</span>
                </div>
                <div class="w-full bg-slate-700 rounded-full h-3">
                    <div id="planStrengthBar" class="h-3 rounded-full bg-gradient-to-r from-yellow-500 to-orange-500 transition-all duration-500" style="width: 0%"></div>
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
        const badge = document.getElementById('signalTypeBadge');
        const direction = document.getElementById('signalDirection');
        const strength = document.getElementById('signalStrength');
        const icon = document.getElementById('signalIcon');
        const bar = document.getElementById('strengthBar');
        const badgeIcon = document.getElementById('signalBadgeIcon');
        const badgeText = document.getElementById('signalBadgeText');

        // تحديث الـ Badge الرئيسي
        if (badge) {
            badge.classList.remove('hidden');
            if (!signal || signal.direction === 'WAIT') {
                badgeIcon.innerText = '⏳';
                badgeText.innerText = 'انتظار';
                badgeText.className = 'text-3xl font-bold text-slate-400';
            } else if (signal.direction === 'BUY') {
                badgeIcon.innerText = '📈';
                badgeText.innerText = 'شراء';
                badgeText.className = 'text-3xl font-bold text-green-400';
            } else {
                badgeIcon.innerText = '📉';
                badgeText.innerText = 'بيع';
                badgeText.className = 'text-3xl font-bold text-red-400';
            }
        }

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
    function updateTradePlanWidget(plan, signal) {
        const widget = document.getElementById('tradePlanWidget');

        if (plan && signal && signal.direction !== 'WAIT') {
            // تحديث نوع الإشارة
            const signalTypeEl = document.getElementById('planSignalType');
            if (signalTypeEl) {
                if (signal.direction === 'BUY') {
                    signalTypeEl.innerText = 'BUY - شراء';
                    signalTypeEl.className = 'mr-auto px-3 py-1 rounded-full text-sm font-bold bg-green-500/20 text-green-400 border border-green-500/30';
                } else {
                    signalTypeEl.innerText = 'SELL - بيع';
                    signalTypeEl.className = 'mr-auto px-3 py-1 rounded-full text-sm font-bold bg-red-500/20 text-red-400 border border-red-500/30';
                }
            }

            document.getElementById('planEntry').innerText = plan.entry?.toFixed(2) || plan.entry_zone?.toFixed(2) || '---';
            document.getElementById('planTp1').innerText = plan.tp1?.toFixed(2) || '---';
            document.getElementById('planTp2').innerText = plan.tp2?.toFixed(2) || '---';
            document.getElementById('planTp3').innerText = plan.tp3?.toFixed(2) || '---';
            document.getElementById('planSl').innerText = plan.sl?.toFixed(2) || '---';
            document.getElementById('planLot').innerText = plan.lot_size || '0.01';
            document.getElementById('planRisk').innerText = (plan.risk_percent || '1.5') + '%';

            // تحديث قوة الإشارة في الويدجت
            const strengthEl = document.getElementById('planSignalStrength');
            const strengthBar = document.getElementById('planStrengthBar');
            if (strengthEl && signal.strength) {
                strengthEl.innerText = signal.strength + '/10';
            }
            if (strengthBar && signal.strength) {
                const percentage = (signal.strength / 10) * 100;
                strengthBar.style.width = percentage + '%';

                if (signal.direction === 'BUY') {
                    strengthBar.className = 'h-3 rounded-full bg-gradient-to-r from-green-500 to-emerald-500 transition-all duration-500';
                } else {
                    strengthBar.className = 'h-3 rounded-full bg-gradient-to-r from-red-500 to-rose-500 transition-all duration-500';
                }
            }

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
            if (data.trade_plan && data.trading_signal) {
                updateTradePlanWidget(data.trade_plan, data.trading_signal);
            } else if (data.trade_setup && data.trading_signal) {
                updateTradePlanWidget(data.trade_setup, data.trading_signal);
            } else {
                document.getElementById('tradePlanWidget').classList.add('hidden');
                document.getElementById('signalTypeBadge').classList.remove('hidden');
                // إظهار حالة الانتظار
                const badgeIcon = document.getElementById('signalBadgeIcon');
                const badgeText = document.getElementById('signalBadgeText');
                if (badgeIcon && badgeText) {
                    badgeIcon.innerText = '⏳';
                    badgeText.innerText = 'انتظار';
                    badgeText.className = 'text-3xl font-bold text-slate-400';
                }
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
