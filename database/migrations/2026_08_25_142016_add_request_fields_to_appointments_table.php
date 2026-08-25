<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            // A request has no real schedule yet — staff fill these in on confirm.
            $table->dateTime('start_time')->nullable()->change();
            $table->dateTime('end_time')->nullable()->change();
            $table->string('type')->nullable()->change();
            $table->foreignId('provider_id')->nullable()->change();

            $table->string('service_interest')->nullable();
            $table->string('dentist_preference')->nullable();
            $table->date('preferred_date')->nullable();
            $table->string('preferred_time_of_day')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'service_interest',
                'dentist_preference',
                'preferred_date',
                'preferred_time_of_day',
                'notes',
            ]);

            $table->dateTime('start_time')->nullable(false)->change();
            $table->dateTime('end_time')->nullable(false)->change();
            $table->string('type')->nullable(false)->change();
            $table->foreignId('provider_id')->nullable(false)->change();
        });
    }
};
