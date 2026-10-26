<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Encrypt the project-environment passwords that were stored in plain text — security audit 2026-10-26,
 * finding 14. The model now casts `password` as `encrypted`; this widens the column (ciphertext is far
 * longer than a password) and converts the rows already on disk.
 *
 * Idempotent: a value that already decrypts is left alone, so a re-run — or a row the new cast wrote
 * before this reached it — is not double-encrypted. Reads the raw column through the query builder, not
 * the cast, so it sees the stored string whatever the model would do with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('project_environments')) {
            return;
        }

        // Laravel's encrypted string runs to a few hundred characters; a VARCHAR(255) truncates it.
        Schema::table('project_environments', function (Blueprint $table): void {
            $table->text('password')->nullable()->change();
        });

        // get()->each, not the builder's chunked each(): updating rows mid-chunk skips some of them.
        DB::table('project_environments')
            ->whereNotNull('password')
            ->where('password', '!=', '')
            ->get()
            ->each(function (object $row): void {
                try {
                    Crypt::decryptString($row->password);

                    return; // already encrypted
                } catch (DecryptException) {
                    // plain text — encrypt it below
                }

                DB::table('project_environments')
                    ->where('id', $row->id)
                    ->update(['password' => Crypt::encryptString($row->password)]);
            });
    }

    public function down(): void
    {
        // No down: decrypting back to plain text would re-introduce the finding.
    }
};
