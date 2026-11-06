<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     * Добавляем в спровочник офисов рабочее время
     *
     */
    public function up(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->time('working_hours_from')->default('09:00:00')->isNotEmpty();
            $table->time('working_hours_to')->default('20:00:00')->isNotEmpty();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offices', function (Blueprint $table) {
            $table->dropColumn('working_hours_from');
            $table->dropColumn('working_hours_to');
        });
    }
};
