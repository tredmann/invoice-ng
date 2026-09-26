<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_ranges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            // Unique: one Nummernkreis per Firma, shared by every Beleg that is
            // an invoice within the meaning of §14 UStG. Its own table rather
            // than columns on `companies`, because the row is locked for the
            // whole length of a PDF render — locking the company row for that
            // would block the settings page and the switcher along with it.
            $table->foreignUuid('company_id')->constrained()->restrictOnDelete();
            // „RE-" — the prefix carries its own separator, so a company that
            // wants none simply leaves it empty.
            $table->string('prefix')->nullable();
            $table->unsignedSmallInteger('padding')->default(4);
            // The number the next draw will take. Not a record of the last one:
            // the range is read, used and incremented under one lock.
            $table->integer('next_value')->default(1);
            $table->boolean('include_year')->default(true);
            $table->boolean('reset_yearly')->default(true);
            $table->integer('last_reset_year')->nullable();
            // What makes the raise-only guard possible: without it there is no
            // way to tell "migrating in, pick your start" from "silently
            // reissuing numbers that are already on a customer's invoice".
            $table->integer('drawn_count')->default(0);
            $table->timestamps();

            // One Nummernkreis per Firma. As its own statement, not chained
            // onto constrained(): that returns a ForeignKeyDefinition, and
            // ->unique() on it sets a property of the constraint rather than
            // creating an index — silently, and with no second range possible
            // through the interface to reveal it.
            $table->unique('company_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_ranges');
    }
};
