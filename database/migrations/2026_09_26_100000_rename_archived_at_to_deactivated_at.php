<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Renames `archived_at` to `deactivated_at` on both tenant-owned tables.
 *
 * The column is renamed rather than the create migrations being edited, so a
 * development database that already holds companies and customers keeps them.
 * See CONTEXT.md: in German bookkeeping "Archivierung" is the ten-year
 * retention of issued documents (§147 AO, §14b UStG), which this application
 * will genuinely do — the word is kept free for that, and taking a customer
 * out of the pickers is deaktivieren.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['companies', 'customers'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->renameColumn('archived_at', 'deactivated_at');
            });
        }
    }

    public function down(): void
    {
        foreach (['companies', 'customers'] as $table) {
            Schema::table($table, function (Blueprint $table): void {
                $table->renameColumn('deactivated_at', 'archived_at');
            });
        }
    }
};
