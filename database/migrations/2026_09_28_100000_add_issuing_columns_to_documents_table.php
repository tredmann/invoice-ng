<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            // The Belegnummer as it prints. It was an integer while nothing
            // drew one; DrawNextNumber returns the formatted value — prefix,
            // year and padding — and that string is what §14 Abs. 4 Nr. 2 UStG
            // calls the number and what must never change afterwards. The
            // counter behind it stays in `number_ranges`, which is the only
            // place that has to count.
            //
            // Dropped and re-added rather than converted: no issued Beleg
            // exists anywhere, so there is no data to preserve and a plain
            // column is clearer than an ALTER with a USING clause.
            $table->dropUnique(['company_id', 'number']);
            $table->dropColumn('number');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('number', 32)->nullable()->after('status');
            $table->unique(['company_id', 'number']);

            // The Fälligkeitsdatum, computed once from issued_on plus the
            // Zahlungsziel. Stored rather than derived on read because the
            // Zahlungsziel is frozen and the date is queried — the Offene
            // Posten list and the Überfällig badge both filter on it.
            $table->date('due_on')->nullable();

            // §6: totals are stored on the document at issue, not recomputed
            // on display. Integer cents, through MoneyCast.
            //
            // The per-Steuersatz groups are deliberately NOT stored. They are
            // derivable from Positionen that are immutable after issue, so a
            // second copy could only ever disagree with the lines it claims to
            // sum.
            $table->bigInteger('net_total')->nullable();
            $table->bigInteger('tax_total')->nullable();
            $table->bigInteger('gross_total')->nullable();

            // The Festschreibung: the seller identity and the buyer's
            // Rechnungsanschrift as they read at the moment of issue. jsonb
            // and a plain cast to a readonly DTO (tech stack §6.3).
            $table->jsonb('frozen_block')->nullable();

            // The frozen file. A path on the documents disk — never a URL, and
            // never regenerated. The hash is the proof that a file served in
            // 2029 is byte-identical to the one the customer received.
            $table->string('pdf_path')->nullable();
            $table->string('pdf_sha256', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropUnique(['company_id', 'number']);
            $table->dropColumn([
                'number',
                'due_on',
                'net_total',
                'tax_total',
                'gross_total',
                'frozen_block',
                'pdf_path',
                'pdf_sha256',
            ]);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->integer('number')->nullable()->after('status');
            $table->unique(['company_id', 'number']);
        });
    }
};
