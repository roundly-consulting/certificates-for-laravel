<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\PackageToolkit\Enums\KeyType;

return new class extends Migration
{
    public function up(): void
    {
        // Absent reads as bigint; an unrecognized value throws, so a typo in the
        // host's config never builds the wrong morph key type.
        $keyType = KeyType::fromConfig('certificates.key_type');

        Schema::create((string) config('certificates.table', 'certificates'), function (Blueprint $table) use ($keyType): void {
            $table->id();
            $table->string('name')->index();
            $table->string('domain')->index();
            $table->string('driver')->default('kubernetes')->index();
            $table->string('status')->default(CertificateStatus::Pending->value)->index();
            $table->string('issuer')->nullable();
            $table->string('serial')->nullable();
            $table->string('fingerprint')->nullable();
            $table->morphKey('certifiable', $keyType, nullable: true);
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('last_renewed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->jsonb('meta')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['driver', 'name']);
        });
    }
};
