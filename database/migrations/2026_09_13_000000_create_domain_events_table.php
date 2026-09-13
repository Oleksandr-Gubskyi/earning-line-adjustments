<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The single append-only table that holds every recorded fact.
 *
 * Rows are never updated and never deleted. The unique key on
 * (stream_id, stream_version) is not just an integrity constraint: it is the
 * entire optimistic concurrency mechanism, so two writers holding the same
 * version cannot both succeed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('domain_events', function (Blueprint $table): void {
            // Global ordering and a display tie-break. Deliberately NOT a cursor for
            // projections: with innodb_autoinc_lock_mode=2 ids are handed out at
            // insert time rather than at commit time, so a reader can miss one.
            $table->id();

            // ascii_bin keeps the index at 36 bytes instead of 144 under utf8mb4,
            // and compares UUIDs byte for byte.
            $table->char('stream_id', 36)->charset('ascii')->collation('ascii_bin');

            $table->unsignedInteger('stream_version');

            // A logical name such as earning_line.manual_adjustment_added, never a
            // class name: a stored FQCN turns a rename into a data migration.
            $table->string('event_type', 100);

            // JSON rather than TEXT for write-time validation: a serializer bug fails
            // at append instead of years later while replaying an immutable log.
            $table->json('payload');

            // Microsecond precision, because every row of one batch insert shares a
            // timestamp. Ordering comes from stream_version, never from this column.
            $table->dateTime('recorded_at', 6);

            $table->unique(['stream_id', 'stream_version'], 'uniq_stream_version');

            // No separate index on stream_id: the unique key's left prefix already
            // serves reads of a single stream, in version order, without a filesort.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('domain_events');
    }
};
