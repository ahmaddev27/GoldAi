<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Scalper Pro — ذهب & بيتكوين</title>
    <script src="https://cdn.jsdelivr.net/npm/axios/dist/axios.min.js"></script>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Arabic:wght@300;400;500;700&family=IBM+Plex+Mono:wght@400;600&display=swap" rel="stylesheet">

    <style>
        :root{
            --bg:#07090e; --bg2:#0c0e16; --bg3:#11131e; --bdr:#1a1d2e;
            --txt:#b8c4d8; --dim:#4a5270; --dim2:#6b7590;
            --gold:#c8a020; --gold2:#e6bb38;
            --green:#28b870; --red:#d94f4f;
            --blue:#3d7fff; --purple:#7c5fec;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html{scroll-behavior:smooth;}
        body{
            font-family:'IBM Plex Sans Arabic',sans-serif;
            background:var(--bg);
            color:var(--txt);
            min-height:100vh;
            background-image:linear-gradient(rgba(200,160,32,.025) 1px,transparent 1px),
            linear-gradient(90deg,rgba(200,160,32,.025) 1px,transparent 1px);
            background-size:48px 48px;
        }
        .mono{font-family:'IBM Plex Mono',monospace;}

        /* ── cards ── */
        .card{background:var(--bg2);border:1px solid var(--bdr);border-radius:10px;padding:14px;}
        .card-gold{background:linear-gradient(135deg,rgba(200,160,32,.07),rgba(200,160,32,.02));
            border:1px solid rgba(200,160,32,.22);border-radius:10px;padding:14px;}
        .card-btc{background:linear-gradient(135deg,rgba(247,147,26,.06),rgba(247,147,26,.01));
            border:1px solid rgba(247,147,26,.2);border-radius:10px;padding:14px;}

        /* ── signal states ── */
        .sig-wait{background:var(--bg2);border:1px solid var(--bdr);border-radius:10px;padding:18px;}
        .sig-buy {background:linear-gradient(135deg,rgba(40,184,112,.07),rgba(40,184,112,.01));
            border:1px solid rgba(40,184,112,.3);border-radius:10px;padding:18px;}
        .sig-sell{background:linear-gradient(135deg,rgba(217,79,79,.07),rgba(217,79,79,.01));
            border:1px solid rgba(217,79,79,.3);border-radius:10px;padding:18px;}

        /* ── tags ── */
        .tag{display:inline-flex;align-items:center;font-size:10.5px;padding:2px 7px;
            border-radius:4px;font-family:'IBM Plex Mono',monospace;white-space:nowrap;line-height:1.6;}
        .tg{background:rgba(200,160,32,.1);color:var(--gold2);border:1px solid rgba(200,160,32,.2);}
        .tb{background:rgba(40,184,112,.1);color:var(--green);border:1px solid rgba(40,184,112,.2);}
        .tr{background:rgba(217,79,79,.1); color:var(--red);  border:1px solid rgba(217,79,79,.2);}
        .td{background:rgba(74,82,112,.1); color:var(--dim2); border:1px solid rgba(74,82,112,.18);}
        .tp{background:rgba(124,95,236,.1);color:var(--purple);border:1px solid rgba(124,95,236,.2);}
        .to{background:rgba(247,147,26,.1);color:#f79320;border:1px solid rgba(247,147,26,.2);}

        /* ── bars ── */
        .bar{height:4px;background:rgba(255,255,255,.05);border-radius:2px;overflow:hidden;}
        .bar>span{display:block;height:100%;border-radius:2px;transition:width .45s ease;}

        /* ── spinner ── */
        .spin{width:15px;height:15px;border:2px solid rgba(255,255,255,.07);
            border-top-color:var(--gold);border-radius:50%;animation:sp .65s linear infinite;}
        @keyframes sp{to{transform:rotate(360deg)}}

        /* ── score ring ── */
        .ring{position:relative;width:52px;height:52px;flex-shrink:0;}
        .ring svg{transform:rotate(-90deg);}
        .ring-val{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;
            font-size:.7rem;font-weight:700;font-family:'IBM Plex Mono',monospace;}

        /* ── tabs ── */
        .tab-btn{padding:8px 20px;border-radius:8px;border:1px solid var(--bdr);
            background:transparent;color:var(--dim2);cursor:pointer;font-size:.85rem;
            font-family:'IBM Plex Sans Arabic',sans-serif;transition:.2s;white-space:nowrap;}
        .tab-btn.active-gold{background:rgba(200,160,32,.12);border-color:rgba(200,160,32,.35);color:var(--gold2);}
        .tab-btn.active-btc {background:rgba(247,147,26,.1); border-color:rgba(247,147,26,.3); color:#f79320;}
        .tab-btn:hover:not(.active-gold):not(.active-btc){background:var(--bg3);}

        /* ── button ── */
        .btn{background:linear-gradient(135deg,#a87018,#c8a020);color:#000;font-weight:700;
            border-radius:9px;padding:11px 0;width:100%;border:none;cursor:pointer;font-size:.92rem;
            font-family:'IBM Plex Sans Arabic',sans-serif;transition:opacity .2s,transform .1s;
            display:flex;align-items:center;justify-content:center;gap:8px;}
        .btn:hover:not(:disabled){opacity:.88;}
        .btn:active:not(:disabled){transform:scale(.97);}
        .btn:disabled{opacity:.45;cursor:not-allowed;}
        .btn-btc{background:linear-gradient(135deg,#c25f00,#f79320);}

        /* ── toggle ── */
        .tog{position:relative;width:32px;height:17px;flex-shrink:0;}
        .tog input{display:none;}
        .tog-t{position:absolute;inset:0;background:#1e2235;border-radius:9px;cursor:pointer;transition:.25s;}
        .tog-t::after{content:'';position:absolute;width:11px;height:11px;top:3px;right:3px;
            background:#fff;border-radius:50%;transition:.25s;}
        .tog input:checked+.tog-t{background:var(--gold);}
        .tog input:checked+.tog-t::after{right:18px;}

        /* ── markdown ── */
        #ai-out h1,#ai-out h2,#ai-out h3{color:var(--gold2);font-weight:600;margin:.7rem 0 .3rem;font-size:.92em;}
        #ai-out p{margin-bottom:.55rem;color:var(--txt);line-height:1.75;font-size:.86em;}
        #ai-out ul{list-style:disc;padding-right:1rem;margin-bottom:.6rem;}
        #ai-out li{margin-bottom:.2rem;color:var(--txt);font-size:.86em;}
        #ai-out strong{color:var(--gold2);}

        /* ── dot ── */
        .dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
        .dok{background:var(--green);box-shadow:0 0 5px rgba(40,184,112,.5);}
        .dwn{background:var(--dim);}
        .derr{background:var(--red);}

        /* ── layout ── */
        #mainGrid{display:grid;grid-template-columns:210px 1fr;gap:12px;}
        @media(max-width:680px){#mainGrid{grid-template-columns:1fr;}}
    </style>
</head>

<body>
<div style="max-width:1160px;margin:0 auto;padding:14px 10px;display:flex;flex-direction:column;gap:11px;">

    <!-- ══ HEADER ══ -->
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
        <div>
            <div style="font-size:1.35rem;font-weight:700;color:#fff;">⚡ Scalper Pro</div>
            <div style="font-size:.7rem;color:var(--dim);">$100 · Lot 0.01 · Risk 1.5% · v7</div>
        </div>
        <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <!-- Asset Tabs -->
            <button id="tabGold" class="tab-btn active-gold" onclick="switchAsset('gold')">🥇 الذهب</button>
            <button id="tabBtc"  class="tab-btn"             onclick="switchAsset('btc')">₿ بيتكوين</button>
            <div style="display:flex;align-items:center;gap:6px;background:var(--bg2);border:1px solid var(--bdr);border-radius:8px;padding:4px 10px;">
                <div id="statusDot" class="dot dwn"></div>
                <span id="statusTxt" style="font-size:.7rem;color:var(--dim);">في انتظار التحليل</span>
            </div>
        </div>
    </div>

    <!-- ══ PRICE TICKER ══ -->
    <div id="tickerCard" class="card-gold" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;align-items:flex-end;gap:14px;">
            <div>
                <div id="tickerLabel" style="font-size:.68rem;color:var(--dim);margin-bottom:2px;">XAU/USD</div>
                <div style="display:flex;align-items:baseline;gap:6px;">
                    <span id="priceNum" class="mono" style="font-size:2.5rem;font-weight:700;color:#fff;letter-spacing:-1px;">---.--</span>
                    <span style="font-size:.72rem;color:var(--dim);">USD</span>
                </div>
            </div>
            <div style="padding-bottom:5px;">
                <div id="priceChg"    style="font-size:.88rem;font-weight:600;color:var(--dim);">--</div>
                <div id="priceChgPct" style="font-size:.68rem;color:var(--dim);">--</div>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;">
            <span id="srcBadge" class="tag td">--</span>
            <span id="sessLabel" class="tag td">--</span>
            <div style="font-size:.65rem;color:var(--dim);">تحديث مع كل تحليل فقط</div>
        </div>
    </div>

    <!-- ══ SIGNAL CARD ══ -->
    <div id="sigCard" class="sig-wait">
        <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <!-- Left: emoji + text -->
            <div style="display:flex;align-items:center;gap:14px;">
                <span id="sigEmoji" style="font-size:2.8rem;line-height:1;">⏳</span>
                <div>
                    <div id="sigTitle"  style="font-size:1.65rem;font-weight:700;color:var(--dim);">انتظار</div>
                    <div id="sigReason" style="font-size:.74rem;color:var(--dim);margin-top:2px;">--</div>
                </div>
            </div>
            <!-- Right: score ring + trend + strength -->
            <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
                <!-- Score ring -->
                <div class="ring">
                    <svg width="52" height="52" viewBox="0 0 52 52">
                        <circle cx="26" cy="26" r="22" fill="none" stroke="rgba(255,255,255,.06)" stroke-width="4"/>
                        <circle id="ringCircle" cx="26" cy="26" r="22" fill="none" stroke="var(--dim)" stroke-width="4"
                                stroke-dasharray="138.2" stroke-dashoffset="138.2" stroke-linecap="round"/>
                    </svg>
                    <div id="ringVal" class="ring-val" style="color:var(--dim);">0</div>
                </div>
                <div>
                    <div style="font-size:.62rem;color:var(--dim);margin-bottom:3px;">قوة / 10</div>
                    <div style="display:flex;align-items:center;gap:6px;">
                        <div class="bar" style="width:80px;"><span id="strBar" style="width:0%;background:var(--dim);"></span></div>
                        <span id="strVal" class="mono" style="font-size:.75rem;color:var(--dim);">0/10</span>
                    </div>
                    <div style="display:flex;align-items:center;gap:6px;margin-top:5px;">
                        <div style="font-size:.62rem;color:var(--dim);">1h:</div>
                        <span id="h1Badge" class="tag td">--</span>
                        <span id="sessBadge" class="tag td">--</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Score bars (bull vs bear) -->
        <div id="scoreBars" style="display:none;margin-top:12px;display:grid;grid-template-columns:1fr 1fr;gap:8px;">
            <div>
                <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--dim);margin-bottom:3px;">
                    <span>📈 نقاط شراء</span><span id="bullScore" class="mono">-</span>
                </div>
                <div class="bar"><span id="bullBar" style="width:0%;background:var(--green);"></span></div>
            </div>
            <div>
                <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--dim);margin-bottom:3px;">
                    <span>📉 نقاط بيع</span><span id="bearScore" class="mono">-</span>
                </div>
                <div class="bar"><span id="bearBar" style="width:0%;background:var(--red);"></span></div>
            </div>
        </div>

        <!-- Warnings -->
        <div id="warnBox" style="display:none;margin-top:8px;padding:7px 10px;background:rgba(200,160,32,.06);
         border:1px solid rgba(200,160,32,.2);border-radius:7px;font-size:.72rem;color:var(--gold);"></div>
    </div>

    <!-- ══ TRADE PLAN ══ -->
    <div id="planCard" style="display:none;" class="card" style="border-color:rgba(200,160,32,.2);">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:11px;">
            <span style="font-weight:600;color:#fff;font-size:.9rem;">📊 خطة التداول</span>
            <span id="planBadge" class="tag td" style="margin-right:auto;">--</span>
            <span id="planRR"  class="tag tg">RR --</span>
        </div>

        <!-- Entry -->
        <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 12px;
                background:rgba(61,127,255,.04);border:1px solid rgba(61,127,255,.13);
                border-radius:8px;margin-bottom:9px;">
            <span style="font-size:.78rem;color:var(--dim);">🎯 الدخول</span>
            <span id="pEntry" class="mono" style="font-size:1.3rem;font-weight:600;color:var(--blue);">---</span>
        </div>

        <!-- SL / TPs -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-bottom:9px;">
            <div style="padding:9px 6px;background:rgba(217,79,79,.04);border:1px solid rgba(217,79,79,.12);border-radius:7px;text-align:center;">
                <div style="font-size:.6rem;color:var(--dim);margin-bottom:3px;">⛔ SL</div>
                <div id="pSl"   class="mono" style="font-size:.82rem;font-weight:600;color:var(--red);">--</div>
                <div id="pSlD"  style="font-size:.58rem;color:#333a52;margin-top:1px;">-- pts</div>
            </div>
            <div style="padding:9px 6px;background:rgba(40,184,112,.04);border:1px solid rgba(40,184,112,.12);border-radius:7px;text-align:center;">
                <div style="font-size:.6rem;color:var(--dim);margin-bottom:3px;">TP1 ⚡</div>
                <div id="pTp1"  class="mono" style="font-size:.82rem;font-weight:600;color:var(--green);">--</div>
                <div id="pTp1D" style="font-size:.58rem;color:#333a52;margin-top:1px;">-- pts</div>
            </div>
            <div style="padding:9px 6px;background:rgba(40,184,112,.03);border:1px solid rgba(40,184,112,.09);border-radius:7px;text-align:center;">
                <div style="font-size:.6rem;color:var(--dim);margin-bottom:3px;">TP2</div>
                <div id="pTp2"  class="mono" style="font-size:.82rem;font-weight:600;color:#4ade80;">--</div>
                <div id="pTp2D" style="font-size:.58rem;color:#333a52;margin-top:1px;">RR --</div>
            </div>
            <div style="padding:9px 6px;background:rgba(40,184,112,.02);border:1px solid rgba(40,184,112,.07);border-radius:7px;text-align:center;">
                <div style="font-size:.6rem;color:var(--dim);margin-bottom:3px;">TP3</div>
                <div id="pTp3"  class="mono" style="font-size:.82rem;font-weight:600;color:#86efac;">--</div>
                <div id="pTp3D" style="font-size:.58rem;color:#333a52;margin-top:1px;">هدف</div>
            </div>
        </div>

        <!-- Stats -->
        <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:7px;margin-bottom:9px;">
            <div style="padding:8px 6px;background:var(--bg3);border-radius:7px;text-align:center;">
                <div style="font-size:.58rem;color:var(--dim);">اللوت</div>
                <div id="pLot"    class="mono" style="font-size:.85rem;font-weight:600;color:var(--gold2);">--</div>
            </div>
            <div style="padding:8px 6px;background:rgba(217,79,79,.04);border-radius:7px;text-align:center;">
                <div style="font-size:.58rem;color:var(--dim);">خسارة SL</div>
                <div id="pLoss"   class="mono" style="font-size:.85rem;font-weight:600;color:var(--red);">--</div>
            </div>
            <div style="padding:8px 6px;background:rgba(40,184,112,.04);border-radius:7px;text-align:center;">
                <div style="font-size:.58rem;color:var(--dim);">ربح TP1</div>
                <div id="pProfit" class="mono" style="font-size:.85rem;font-weight:600;color:var(--green);">--</div>
            </div>
            <div style="padding:8px 6px;background:var(--bg3);border-radius:7px;text-align:center;">
                <div style="font-size:.58rem;color:var(--dim);">Swing Ref</div>
                <div id="pSwing"  class="mono" style="font-size:.82rem;font-weight:600;color:var(--purple);">--</div>
            </div>
        </div>

        <div style="padding:7px 10px;background:rgba(200,160,32,.05);border:1px solid rgba(200,160,32,.14);border-radius:7px;font-size:.7rem;color:var(--gold);">
            💡 عند TP1: أغلق 50% وحرّك SL إلى نقطة الدخول (Breakeven)
        </div>
    </div>

    <!-- ══ MAIN GRID ══ -->
    <div id="mainGrid">

        <!-- ─── SIDEBAR ─── -->
        <div style="display:flex;flex-direction:column;gap:9px;">

            <div class="card">
                <div style="font-size:.62rem;color:var(--dim);font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:9px;">المؤشرات</div>
                <div style="margin-bottom:7px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:2px;font-size:.78rem;">
                        <span style="color:var(--dim);">RSI 1m</span>
                        <span id="rsi1m" class="mono" style="color:#e8c34a;">--</span>
                    </div>
                    <div class="bar"><span id="rsiB1" style="width:50%;background:#e8c34a;"></span></div>
                </div>
                <div style="margin-bottom:10px;">
                    <div style="display:flex;justify-content:space-between;margin-bottom:2px;font-size:.78rem;">
                        <span style="color:var(--dim);">RSI 5m</span>
                        <span id="rsi5m" class="mono" style="color:#e8c34a;">--</span>
                    </div>
                    <div class="bar"><span id="rsiB5" style="width:50%;background:#e8c34a;"></span></div>
                </div>
                <div style="display:flex;flex-direction:column;gap:5px;font-size:.77rem;">
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">Stoch</span>
                        <span id="stoch" class="mono" style="color:#c084fc;">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">EMA12</span>
                        <span id="ema12" class="mono" style="color:#22d3ee;">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">EMA20</span>
                        <span id="ema20" class="mono" style="color:#60a5fa;">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">EMA50</span>
                        <span id="ema50" class="mono" style="color:#a78bfa;">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">ATR</span>
                        <span id="atr" class="mono" style="color:#fb923c;">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">MACD Hist</span>
                        <span id="macdH" class="mono">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">BB %B</span>
                        <span id="bbPct" class="mono" style="color:var(--txt);">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;">
                        <span style="color:var(--dim);">Candle</span>
                        <span id="candlePatt" class="tag td" style="font-size:.62rem;">--</span>
                    </div>
                </div>
            </div>

            <!-- Levels -->
            <div class="card" style="font-size:.76rem;">
                <div style="font-size:.62rem;color:var(--dim);font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:9px;">Key Levels</div>
                <div style="display:flex;flex-direction:column;gap:4px;">
                    <div style="display:flex;justify-content:space-between;"><span style="color:#f87171;">R2</span><span id="r2" class="mono" style="color:#f87171;">--</span></div>
                    <div style="display:flex;justify-content:space-between;"><span style="color:#fca5a5;">R1</span><span id="r1" class="mono" style="color:#fca5a5;">--</span></div>
                    <div style="display:flex;justify-content:space-between;font-weight:600;"><span style="color:#fff;">P</span><span id="pp" class="mono" style="color:#fff;">--</span></div>
                    <div style="display:flex;justify-content:space-between;"><span style="color:#86efac;">S1</span><span id="s1" class="mono" style="color:#86efac;">--</span></div>
                    <div style="display:flex;justify-content:space-between;"><span style="color:#4ade80;">S2</span><span id="s2" class="mono" style="color:#4ade80;">--</span></div>
                    <div style="border-top:1px solid var(--bdr);margin:4px 0;"></div>
                    <div style="display:flex;justify-content:space-between;font-size:.7rem;">
                        <span style="color:var(--dim);">Swing H</span>
                        <span id="swH" class="mono" style="color:#f87171;">--</span>
                    </div>
                    <div style="display:flex;justify-content:space-between;font-size:.7rem;">
                        <span style="color:var(--dim);">Swing L</span>
                        <span id="swL" class="mono" style="color:#4ade80;">--</span>
                    </div>
                </div>
            </div>

            <!-- Analyze button -->
            <button id="analyzeBtn" class="btn" onclick="fetchAnalysis()">
                <span id="btnTxt">🔍 تحليل الآن</span>
                <div id="btnSpin" class="spin" style="display:none;"></div>
            </button>

            <!-- Auto refresh -->
            <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:7px 11px;background:var(--bg2);border:1px solid var(--bdr);border-radius:8px;">
                <span style="font-size:.72rem;color:var(--dim);">تحديث تلقائي كل 90s</span>
                <label class="tog"><input type="checkbox" id="autoTog" checked><div class="tog-t"></div></label>
            </div>

            <div style="text-align:center;font-size:.65rem;color:var(--dim);">
                آخر تحليل: <span id="lastTime" class="mono">--:--</span>
            </div>
        </div>

        <!-- ─── MAIN COLUMN ─── -->
        <div style="display:flex;flex-direction:column;gap:10px;">

            <!-- Chart -->
            <div class="card" style="padding:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:7px;">
                    <span style="font-size:.68rem;color:var(--dim);">5m · آخر 40 شمعة</span>
                    <span id="trendLabel" class="tag td">--</span>
                </div>
                <div style="height:195px;position:relative;"><canvas id="chart"></canvas></div>
            </div>

            <!-- Condition Tags -->
            <div id="tagsCard" style="display:none;" class="card">
                <div style="font-size:.62rem;color:var(--dim);font-weight:600;text-transform:uppercase;letter-spacing:.07em;margin-bottom:7px;">الإشارات المُفعَّلة</div>
                <div id="tagsList" style="display:flex;flex-wrap:wrap;gap:5px;"></div>
            </div>

            <!-- AI -->
            <div class="card" style="min-height:160px;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:11px;padding-bottom:9px;border-bottom:1px solid var(--bdr);">
                    <div style="width:26px;height:26px;border-radius:6px;background:rgba(200,160,32,.09);
                            border:1px solid rgba(200,160,32,.18);display:flex;align-items:center;justify-content:center;">
                        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--gold)" stroke-width="2">
                            <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"/>
                        </svg>
                    </div>
                    <span style="font-weight:600;color:#fff;font-size:.85rem;">تحليل الذكاء الاصطناعي</span>
                    <div id="aiSpin" class="spin" style="display:none;margin-right:auto;"></div>
                </div>
                <div id="ai-out">
                    <div style="text-align:center;padding:28px;color:var(--dim);font-size:.8rem;">اضغط «تحليل الآن» للبدء</div>
                </div>
            </div>
        </div>
    </div><!-- end mainGrid -->

    <div style="text-align:center;font-size:.62rem;color:#1e2130;padding-bottom:6px;">
        ⚠️ للأغراض التعليمية فقط — التداول ينطوي على مخاطر عالية
    </div>
</div>

<script>
    // ═══════════════════════════════════════════
    // STATE
    // ═══════════════════════════════════════════
    let chart, autoTimer, autoRefresh = true;
    let currentAsset = 'gold';

    // ═══════════════════════════════════════════
    // ASSET SWITCH
    // ═══════════════════════════════════════════
    function switchAsset(asset) {
        currentAsset = asset;
        const tg = document.getElementById('tabGold');
        const tb = document.getElementById('tabBtc');
        const tc = document.getElementById('tickerCard');

        tg.className = 'tab-btn' + (asset === 'gold' ? ' active-gold' : '');
        tb.className = 'tab-btn' + (asset === 'btc'  ? ' active-btc'  : '');

        tc.className = asset === 'gold' ? 'card-gold' : 'card-btc';
        document.getElementById('analyzeBtn').className = 'btn' + (asset === 'btc' ? ' btn-btc' : '');

        // تحديث العنوان
        document.getElementById('tickerLabel').textContent = asset === 'gold' ? 'XAU/USD' : 'BTC/USD';

        // مسح البيانات القديمة
        clearUI();
        fetchAnalysis();
    }

    function clearUI() {
        document.getElementById('priceNum').textContent = '---.--';
        document.getElementById('ai-out').innerHTML = '<div style="text-align:center;padding:28px;color:var(--dim);font-size:.8rem;">جارٍ التبديل...</div>';
        document.getElementById('tagsCard').style.display = 'none';
        document.getElementById('planCard').style.display = 'none';
        document.getElementById('sigCard').className = 'sig-wait';
        document.getElementById('sigTitle').textContent = 'انتظار';
        document.getElementById('sigTitle').style.color = 'var(--dim)';
    }

    // ═══════════════════════════════════════════
    // CHART
    // ═══════════════════════════════════════════
    function initChart() {
        chart = new Chart(document.getElementById('chart').getContext('2d'), {
            type: 'line',
            data: { labels: [], datasets: [{
                    label: 'Price',
                    data: [],
                    borderColor: '#c8a020',
                    backgroundColor: ctx => {
                        const g = ctx.chart.ctx.createLinearGradient(0,0,0,195);
                        g.addColorStop(0,'rgba(200,160,32,.13)');
                        g.addColorStop(1,'rgba(200,160,32,0)');
                        return g;
                    },
                    borderWidth: 1.5, fill: true, tension: 0.3, pointRadius: 0,
                }]},
            options: {
                responsive: true, maintainAspectRatio: false,
                animation: { duration: 250 },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        mode:'index', intersect:false,
                        backgroundColor:'rgba(7,9,14,.97)',
                        titleColor:'#c8a020', bodyColor:'#6b7590',
                        borderColor:'rgba(200,160,32,.18)', borderWidth:1, padding:7,
                    }
                },
                scales: {
                    x: { grid:{color:'rgba(255,255,255,.02)'}, ticks:{color:'#2e3248',font:{size:9},maxTicksLimit:8,maxRotation:0} },
                    y: { grid:{color:'rgba(255,255,255,.03)'}, ticks:{color:'#2e3248',font:{size:9,family:'IBM Plex Mono'},maxTicksLimit:6}, position:'right' }
                },
                interaction:{mode:'nearest',axis:'x',intersect:false}
            }
        });
    }

    // ═══════════════════════════════════════════
    // RENDER SIGNAL
    // ═══════════════════════════════════════════
    function renderSignal(sig) {
        const card  = document.getElementById('sigCard');
        const emoji = document.getElementById('sigEmoji');
        const title = document.getElementById('sigTitle');
        const rsn   = document.getElementById('sigReason');
        const bar   = document.getElementById('strBar');
        const val   = document.getElementById('strVal');
        const ring  = document.getElementById('ringCircle');
        const rVal  = document.getElementById('ringVal');
        const sb    = document.getElementById('scoreBars');
        const wb    = document.getElementById('warnBox');
        const h1b   = document.getElementById('h1Badge');
        const sesB  = document.getElementById('sessBadge');

        card.className = sig?.direction === 'BUY'  ? 'sig-buy'  :
            sig?.direction === 'SELL' ? 'sig-sell' : 'sig-wait';

        const circ = 138.2;
        const str  = sig?.strength ?? 0;
        ring.style.strokeDashoffset = circ - (str / 10) * circ;
        rVal.textContent = str.toFixed(1);

        if (!sig || sig.direction === 'WAIT') {
            emoji.textContent  = '⏳';
            title.textContent  = 'انتظار';
            title.style.color  = 'var(--dim)';
            rsn.textContent    = sig?.reason || '--';
            bar.style.width    = '0%'; bar.style.background = 'var(--dim)';
            val.textContent    = '0/10'; val.style.color = 'var(--dim)';
            ring.style.stroke  = 'var(--dim)';
            rVal.style.color   = 'var(--dim)';
        } else {
            const isBuy = sig.direction === 'BUY';
            const col   = isBuy ? 'var(--green)' : 'var(--red)';
            emoji.textContent  = isBuy ? '📈' : '📉';
            title.textContent  = isBuy ? 'شراء  BUY' : 'بيع  SELL';
            title.style.color  = col;
            rsn.textContent    = sig.reason || '';
            bar.style.background = col; bar.style.width = (str * 10) + '%';
            val.textContent    = str + '/10'; val.style.color = col;
            ring.style.stroke  = col; rVal.style.color = col;
        }

        // score bars
        sb.style.display = 'grid';
        if (sig?.bull !== undefined) {
            const max = 14;
            document.getElementById('bullScore').textContent = sig.bull;
            document.getElementById('bearScore').textContent = sig.bear;
            document.getElementById('bullBar').style.width  = Math.min(100,(sig.bull/max)*100)+'%';
            document.getElementById('bearBar').style.width  = Math.min(100,(sig.bear/max)*100)+'%';
        }

        // warnings
        if (sig?.warnings?.length) {
            wb.style.display  = 'block';
            wb.textContent    = sig.warnings.join(' | ');
        } else { wb.style.display = 'none'; }

        // trend badge
        const t1Map = {BULL:['1h↑','tb'], BEAR:['1h↓','tr'], NEUTRAL:['1h→','td']};
        const [t1t,t1c] = t1Map[sig?.trend_1h] || ['--','td'];
        h1b.textContent = t1t; h1b.className = 'tag ' + t1c;
    }

    // ═══════════════════════════════════════════
    // RENDER PLAN
    // ═══════════════════════════════════════════
    function renderPlan(plan, sig) {
        const card = document.getElementById('planCard');
        if (!plan || !sig || sig.direction === 'WAIT') { card.style.display='none'; return; }
        card.style.display = 'block';

        const isBuy = sig.direction === 'BUY';
        const pb    = document.getElementById('planBadge');
        pb.textContent = isBuy ? 'BUY 📈' : 'SELL 📉';
        pb.className   = 'tag ' + (isBuy ? 'tb' : 'tr');

        document.getElementById('planRR').textContent    = `R/R ${plan.rr_tp1}`;
        document.getElementById('pEntry').textContent    = plan.entry?.toFixed(2)   ?? '---';
        document.getElementById('pSl').textContent       = plan.sl?.toFixed(2)      ?? '---';
        document.getElementById('pSlD').textContent      = `${plan.sl_dist} pts`;
        document.getElementById('pTp1').textContent      = plan.tp1?.toFixed(2)     ?? '---';
        document.getElementById('pTp1D').textContent     = `${plan.tp1_dist} pts`;
        document.getElementById('pTp2').textContent      = plan.tp2?.toFixed(2)     ?? '---';
        document.getElementById('pTp2D').textContent     = `RR ${plan.rr_tp2}`;
        document.getElementById('pTp3').textContent      = plan.tp3?.toFixed(2)     ?? '---';
        document.getElementById('pLot').textContent      = plan.lot;
        document.getElementById('pLoss').textContent     = `-$${plan.risk_usd}`;
        document.getElementById('pProfit').textContent   = `+$${plan.profit_tp1}`;
        document.getElementById('pSwing').textContent    = plan.swing_ref?.toFixed(2) ?? '--';
    }

    // ═══════════════════════════════════════════
    // RENDER INDICATORS
    // ═══════════════════════════════════════════
    function renderIndicators(data) {
        const ind = data.indicators || {};

        // Price
        const pe  = document.getElementById('priceNum');
        const old = parseFloat(pe.textContent.replace(/[^0-9.]/g,'')) || 0;
        pe.style.color = old === 0 ? '#fff' :
            data.current_price > old ? 'var(--green)' :
                data.current_price < old ? 'var(--red)' : '#fff';
        pe.textContent = data.current_price?.toFixed(2) ?? '--';
        setTimeout(() => pe.style.color = '#fff', 2000);

        if (data.price_change !== undefined) {
            const c  = data.price_change;
            const cp = data.price_chg_pct;
            const ce = document.getElementById('priceChg');
            ce.textContent = (c >= 0?'+':'') + c.toFixed(2);
            ce.style.color = c >= 0 ? 'var(--green)' : 'var(--red)';
            document.getElementById('priceChgPct').textContent = (cp>=0?'+':'') + cp.toFixed(2) + '%';
        }

        // Source + session
        const src = document.getElementById('srcBadge');
        src.textContent = data.price_source === 'LIVE' ? '⚡ LIVE' : '🕐 CANDLE';
        src.className   = 'tag ' + (data.price_source === 'LIVE' ? 'tb' : 'tg');

        document.getElementById('sessLabel').textContent = data.session?.label || '--';

        const sesB = document.getElementById('sessBadge');
        sesB.textContent = data.session?.label || '--';
        sesB.className   = 'tag ' + (data.session?.overlap ? 'tg' : data.session?.active ? 'td' : 'tr');

        // Trend label
        document.getElementById('trendLabel').textContent = data.trend_short || '--';

        // RSI bars
        const setRsi = (id, barId, v) => {
            const n = parseFloat(v) || 50;
            document.getElementById(id).textContent   = n.toFixed(1);
            const b = document.getElementById(barId);
            b.style.width      = n + '%';
            b.style.background = n < 30 ? 'var(--green)' : n > 70 ? 'var(--red)' : '#e8c34a';
        };
        if (ind.rsi) { setRsi('rsi1m','rsiB1',ind.rsi.rsi_1m); setRsi('rsi5m','rsiB5',ind.rsi.rsi_5m); }

        if (ind.stoch != null) document.getElementById('stoch').textContent = parseFloat(ind.stoch).toFixed(1);
        if (ind.ema) {
            ['ema12','ema20','ema50'].forEach((id,i) => {
                const v = [ind.ema.ema12,ind.ema.ema20,ind.ema.ema50][i];
                document.getElementById(id).textContent = v?.toFixed(2) ?? '--';
            });
        }
        document.getElementById('atr').textContent = ind.atr?.toFixed(3) ?? '--';

        if (ind.macd) {
            const h = ind.macd.hist ?? 0;
            const mh = document.getElementById('macdH');
            mh.textContent = h.toFixed(4);
            mh.style.color = h > 0 ? 'var(--green)' : 'var(--red)';
        }
        if (ind.bb) document.getElementById('bbPct').textContent = (ind.bb.pct_b ?? '--') + '%';

        if (ind.pivots) {
            document.getElementById('r2').textContent = ind.pivots.R2?.toFixed(2) ?? '--';
            document.getElementById('r1').textContent = ind.pivots.R1?.toFixed(2) ?? '--';
            document.getElementById('pp').textContent = ind.pivots.P?.toFixed(2)  ?? '--';
            document.getElementById('s1').textContent = ind.pivots.S1?.toFixed(2) ?? '--';
            document.getElementById('s2').textContent = ind.pivots.S2?.toFixed(2) ?? '--';
        }
        if (ind.swing) {
            document.getElementById('swH').textContent = ind.swing.high?.toFixed(2) ?? '--';
            document.getElementById('swL').textContent = ind.swing.low?.toFixed(2)  ?? '--';
        }

        if (ind.candle) {
            const cp = document.getElementById('candlePatt');
            cp.textContent = ind.candle.pattern || '--';
            cp.className   = 'tag ' + (ind.candle.bull > 0 ? 'tb' : ind.candle.bear > 0 ? 'tr' : 'td');
        }
    }

    // ═══════════════════════════════════════════
    // RENDER TAGS
    // ═══════════════════════════════════════════
    function renderTags(tags) {
        const card = document.getElementById('tagsCard');
        const list = document.getElementById('tagsList');
        if (!tags?.length) { card.style.display='none'; return; }
        card.style.display = 'block';
        const cls = t => {
            if (t.includes('BULL')||t.includes('LOW')||t.includes('HAMMER')||t.includes('ENGULF')&&t.includes('BULL')) return 'tb';
            if (t.includes('BEAR')||t.includes('HIGH')||t.includes('SHOOTING')||t.includes('ENGULF')&&t.includes('BEAR')) return 'tr';
            if (t.includes('MACD')) return 'tp';
            if (t.includes('1H')) return 'tg';
            if (t.includes('OVERLAP')) return 'tg';
            return 'td';
        };
        list.innerHTML = tags.map(t => `<span class="tag ${cls(t)}">${t}</span>`).join('');
    }

    // ═══════════════════════════════════════════
    // MAIN FETCH
    // ═══════════════════════════════════════════
    async function fetchAnalysis() {
        const btn  = document.getElementById('analyzeBtn');
        const bTxt = document.getElementById('btnTxt');
        const bSp  = document.getElementById('btnSpin');
        const aSp  = document.getElementById('aiSpin');
        const aOut = document.getElementById('ai-out');
        const dot  = document.getElementById('statusDot');
        const stTx = document.getElementById('statusTxt');

        btn.disabled = true;
        bTxt.textContent = 'جارٍ التحليل...';
        bSp.style.display = 'inline-block';
        aSp.style.display = 'inline-block';
        aOut.style.opacity = '0.4';
        dot.className = 'dot dwn';
        stTx.textContent = 'جارٍ التحليل...';

        try {
            const { data } = await axios.get(`/api/get-analysis?asset=${currentAsset}`, { timeout: 55000 });
            if (data.error) throw new Error(data.error);

            renderIndicators(data);
            renderSignal(data.trading_signal);
            renderPlan(data.trade_plan, data.trading_signal);
            renderTags(data.trading_signal?.tags);

            if (data.chart_data_5m?.length) {
                chart.data.labels           = data.chart_times_5m || data.chart_data_5m.map((_,i)=>i);
                chart.data.datasets[0].data = data.chart_data_5m;
                chart.update('none');
            }

            if (data.recommendation) {
                aOut.innerHTML = marked.parse(data.recommendation);
            }
            aOut.style.opacity = '1';

            document.getElementById('lastTime').textContent = data.time ? data.time.substr(11,5) : '--:--';
            dot.className  = 'dot dok';
            stTx.textContent = data.price_source === 'LIVE' ? 'سعر مباشر ✓' : 'سعر آخر شمعة';

        } catch (e) {
            const msg = e.response?.data?.error || e.message || 'خطأ';
            aOut.innerHTML = `<div style="color:var(--red);padding:11px;background:rgba(217,79,79,.05);
                          border:1px solid rgba(217,79,79,.15);border-radius:8px;font-size:.8rem;">❌ ${msg}</div>`;
            aOut.style.opacity = '1';
            dot.className  = 'dot derr';
            stTx.textContent = 'خطأ في الاتصال';
        } finally {
            btn.disabled = false;
            bTxt.textContent = '🔍 تحليل الآن';
            bSp.style.display = 'none';
            aSp.style.display = 'none';
        }
    }

    // ═══════════════════════════════════════════
    // AUTO REFRESH + INIT
    // ═══════════════════════════════════════════
    document.addEventListener('DOMContentLoaded', () => {
        initChart();

        // Responsive grid
        const checkLayout = () => {
            document.getElementById('mainGrid').style.gridTemplateColumns =
                window.innerWidth < 680 ? '1fr' : '210px 1fr';
        };
        checkLayout();
        window.addEventListener('resize', checkLayout);

        // Auto refresh — 90 ثانية فقط
        document.getElementById('autoTog').addEventListener('change', e => {
            autoRefresh = e.target.checked;
            if (autoTimer) clearInterval(autoTimer);
            if (autoRefresh) autoTimer = setInterval(fetchAnalysis, 90000);
        });
        autoTimer = setInterval(() => { if (autoRefresh) fetchAnalysis(); }, 90000);

        // تحليل أولي
        setTimeout(fetchAnalysis, 800);
    });
</script>
</body>
</html>
