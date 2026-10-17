<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Tests\Fixtures\CustomCertificate;

/**
 * The shipped factory builds the model the host configured. Without a `modelName()` override
 * it built its packaged `$model`, so a host seeding through `Certificate::factory()` got
 * packaged rows — without its own casts, events or observers. Exact classes, not `instanceof`:
 * the custom model extends the packaged one, so `toBeInstanceOf` passes either way.
 */
it('builds the configured certificate model', function (): void {
    expect(Certificate::factory()->create()::class)->toBe(CustomCertificate::class)
        ->and(Certificate::factory()->issued()->create()::class)->toBe(CustomCertificate::class)
        ->and(CustomCertificate::factory()->create()::class)->toBe(CustomCertificate::class);
});

it('fires the host model events for a factory-built certificate', function (): void {
    CustomCertificate::resetCreationCount();

    Certificate::factory()->count(2)->create();

    expect(CustomCertificate::creationCount())->toBe(2);
});
