<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('gold_analyses', function (Blueprint $table) {
            $table->id();
            
            // بيانات التحليل الأساسية
            $table->decimal('current_price', 10, 5)->comment('السعر الحالي للذهب');
            $table->longText('recommendation')->comment('توصية التحليل النصية');
            
            // بيانات الدخول والأهداف
            $table->decimal('entry_price', 10, 5)->nullable()->comment('سعر الدخول');
            $table->decimal('tp1', 10, 5)->nullable()->comment('الهدف الأول');
            $table->decimal('tp2', 10, 5)->nullable()->comment('الهدف الثاني');
            $table->decimal('tp3', 10, 5)->nullable()->comment('الهدف الثالث');
            $table->decimal('stop_loss', 10, 5)->nullable()->comment('وقف الخسارة');
            
            // معلومات المخاطرة والاتجاه
            $table->decimal('risk_percent', 5, 2)->default(1.5)->comment('نسبة المخاطرة');
            $table->enum('direction', ['BUY', 'SELL', 'WAIT'])->comment('اتجاه التداول');
            $table->decimal('signal_strength', 4, 2)->default(5)->comment('قوة الإشارة من 0-10');
            
            // حالة التداول
            $table->enum('status', ['pending', 'open', 'closed', 'missed'])->default('pending')->comment('حالة التداول');
            $table->decimal('exit_price', 10, 5)->nullable()->comment('سعر الإغلاق الفعلي');
            $table->string('exit_reason')->nullable()->comment('سبب إغلاق التداول');
            
            // النتائج (بعد إغلاق التداول)
            $table->decimal('profit_loss', 10, 2)->nullable()->comment('الربح أو الخسارة بالدولار');
            $table->decimal('pips_gained', 6, 2)->nullable()->comment('النقاط المكتسبة');
            
            // البيانات الإضافية
            $table->json('trade_plan_json')->nullable()->comment('خطة التداول JSON');
            $table->json('indicators_json')->nullable()->comment('قيم المؤشرات JSON');
            
            // الوقت
            $table->timestamp('analyzed_at')->comment('وقت التحليل');
            $table->timestamp('closed_at')->nullable()->comment('وقت إغلاق التداول');
            $table->timestamps();
            
            // الفهارس
            $table->index('direction');
            $table->index('status');
            $table->index('analyzed_at');
            $table->index(['status', 'profit_loss']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('gold_analyses');
    }
};
