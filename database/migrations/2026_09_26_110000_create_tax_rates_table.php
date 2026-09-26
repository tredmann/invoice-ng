<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_rates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Restrict, not cascade: companies are deactivated, never deleted,
            // and a rate an issued document was computed with must not vanish.
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            // Basis points: 1900 is 19 %. An integer for the reason money is
            // stored in cents — a float can never get near it — and formatted
            // for display in one place on the model.
            $table->integer('rate');
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            // Two rows reading „19 %" would give the Positions picker two
            // entries a user cannot tell apart. The leading column also serves
            // every tenant-scoped query.
            $table->unique(['company_id', 'rate']);
        });

        // „At most one default per company" as a database property rather than
        // as a hook that hopes. The hook clearing the other rows is still
        // written — it is what makes the interface behave; this is what makes
        // its failure survivable. Partial indexes have no Blueprint API.
        DB::statement(
            'create unique index tax_rates_company_default_unique on tax_rates (company_id) where is_default'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_rates');
    }
};
