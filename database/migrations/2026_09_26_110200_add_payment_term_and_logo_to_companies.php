<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            // The Zahlungsziel a new Rechnung and a new Kunde start from. A
            // duration, backed by the PaymentTerm enum — the Fälligkeitsdatum
            // is derived from it and never stored here.
            $table->string('payment_term')->default('net_14');
            // A path on the logos disk, not a URL: the disk is a configuration
            // seam and the renderer reads the file, so nothing here may assume
            // the file is reachable over HTTP.
            $table->string('logo_path')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->dropColumn(['payment_term', 'logo_path']);
        });
    }
};
