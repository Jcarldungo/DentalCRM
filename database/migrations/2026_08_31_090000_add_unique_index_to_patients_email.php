<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Two concurrent public bookings for the same new email created two
     * patient rows; the lookup then returned only the lower id and
     * silently omitted the other's appointments. BookingController
     * closes the window in application code — this closes it in the
     * schema.
     *
     * The column stays nullable and MySQL/MariaDB permits any number of
     * NULLs in a unique index, so staff-created walk-in patients with no
     * email are unaffected. The default utf8mb4_unicode_ci collation
     * makes the index case-insensitive, matching the LOWER(email)
     * lookups in BookingController and AppointmentLookupController.
     */
    public function up(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->unique('email');
        });
    }

    public function down(): void
    {
        Schema::table('patients', function (Blueprint $table) {
            $table->dropUnique(['email']);
        });
    }
};
