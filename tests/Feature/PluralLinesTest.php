<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use RoundlyConsulting\Certificates\Alerts\CertificateExpiryCheck;
use RoundlyConsulting\Certificates\CertificateProviderManager;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;

/**
 * Count lines pick a plural form: one / other in English, one / few (2–4) / many in Slovak.
 */
beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-01 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function expiringCertificates(int $count, int $days): Certificate
{
    $certificates = Certificate::factory()->count($count)->create([
        'status' => CertificateStatus::Issued,
        'issued_at' => CarbonImmutable::now()->subDays(80),
        'expires_at' => CarbonImmutable::now()->addDays($days),
    ]);

    return $certificates->first();
}

it('pluralises the pruned count', function (string $locale, int $count, string $expected): void {
    Certificate::factory()->count($count)->expired()->create(['updated_at' => CarbonImmutable::now()->subDays(60)]);
    app()->setLocale($locale);

    $this->artisan('certificates:prune', ['--days' => '30'])
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'en 1' => ['en', 1, 'Pruned 1 certificate.'],
    'en 3' => ['en', 3, 'Pruned 3 certificates.'],
    'sk 0' => ['sk', 0, 'Odstránilo sa 0 certifikátov.'],
    'sk 1' => ['sk', 1, 'Odstránil sa 1 certifikát.'],
    'sk 3' => ['sk', 3, 'Odstránili sa 3 certifikáty.'],
    'sk 5' => ['sk', 5, 'Odstránilo sa 5 certifikátov.'],
]);

it('pluralises the synced count', function (string $locale, int $count, string $expected): void {
    $provider = new ArrayProvider;

    foreach (range(1, $count) as $i) {
        $provider->generate("generated-tls-s{$i}-com", "s{$i}.example.com");
    }

    app(CertificateProviderManager::class)->extend('array', fn (): ArrayProvider => $provider);
    app()->setLocale($locale);

    $this->artisan('certificates:sync', ['--driver' => 'array'])
        ->expectsOutputToContain($expected)
        ->assertExitCode(0);
})->with([
    'en 1' => ['en', 1, 'Synced 1 certificate from the array provider.'],
    'en 3' => ['en', 3, 'Synced 3 certificates from the array provider.'],
    'sk 1' => ['sk', 1, 'Od poskytovateľa array sa synchronizoval 1 certifikát.'],
    'sk 3' => ['sk', 3, 'Od poskytovateľa array sa synchronizovali 3 certifikáty.'],
    'sk 5' => ['sk', 5, 'Od poskytovateľa array sa synchronizovalo 5 certifikátov.'],
]);

it('pluralises the registry-wide failure count', function (string $locale, int $count, string $expected): void {
    expiringCertificates($count, 3);
    app()->setLocale($locale);

    expect((new CertificateExpiryCheck)->check()->message)->toBe($expected);
})->with([
    'en 1' => ['en', 1, '1 certificate is expired, failed or within the critical expiry window.'],
    'en 3' => ['en', 3, '3 certificates are expired, failed or within the critical expiry window.'],
    'sk 1' => ['sk', 1, '1 certifikát je po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.'],
    'sk 3' => ['sk', 3, '3 certifikáty sú po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.'],
    'sk 5' => ['sk', 5, '5 certifikátov je po platnosti, v stave zlyhania alebo v kritickom období pred vypršaním platnosti.'],
]);

it('pluralises the days left in every expiry band', function (string $locale, int $warning, int $critical, int $days, string $expected): void {
    $certificate = expiringCertificates(1, $days);
    $certificate->forceFill(['domain' => 'app.example.com'])->save();
    app()->setLocale($locale);

    $check = new CertificateExpiryCheck(certificateId: $certificate->id, warningDays: $warning, criticalDays: $critical);

    expect($check->check()->message)->toBe($expected);
})->with([
    'ok en 1' => ['en', 0, 0, 1, 'The certificate for app.example.com is healthy (1 day until expiry).'],
    'ok en 3' => ['en', 0, 0, 3, 'The certificate for app.example.com is healthy (3 days until expiry).'],
    'ok sk 1' => ['sk', 0, 0, 1, 'Certifikát pre app.example.com je v poriadku (platnosť vyprší o 1 deň).'],
    'ok sk 3' => ['sk', 0, 0, 3, 'Certifikát pre app.example.com je v poriadku (platnosť vyprší o 3 dni).'],
    'ok sk 5' => ['sk', 0, 0, 5, 'Certifikát pre app.example.com je v poriadku (platnosť vyprší o 5 dní).'],
    'warning en 1' => ['en', 30, 0, 1, 'The certificate for app.example.com expires in 1 day.'],
    'warning en 3' => ['en', 30, 0, 3, 'The certificate for app.example.com expires in 3 days.'],
    'warning sk 1' => ['sk', 30, 0, 1, 'Platnosť certifikátu pre app.example.com vyprší o 1 deň.'],
    'warning sk 3' => ['sk', 30, 0, 3, 'Platnosť certifikátu pre app.example.com vyprší o 3 dni.'],
    'warning sk 5' => ['sk', 30, 0, 5, 'Platnosť certifikátu pre app.example.com vyprší o 5 dní.'],
    'critical en 1' => ['en', 30, 7, 1, 'The certificate for app.example.com expires in 1 day — renew it to avoid an outage.'],
    'critical en 3' => ['en', 30, 7, 3, 'The certificate for app.example.com expires in 3 days — renew it to avoid an outage.'],
    'critical sk 1' => ['sk', 30, 7, 1, 'Platnosť certifikátu pre app.example.com vyprší o 1 deň – obnovte ho, aby nedošlo k výpadku.'],
    'critical sk 3' => ['sk', 30, 7, 3, 'Platnosť certifikátu pre app.example.com vyprší o 3 dni – obnovte ho, aby nedošlo k výpadku.'],
    'critical sk 5' => ['sk', 30, 7, 5, 'Platnosť certifikátu pre app.example.com vyprší o 5 dní – obnovte ho, aby nedošlo k výpadku.'],
]);

it('renders a zero count without stray whitespace', function (): void {
    // An interval line ({1} …|[2,4] …|[5,*] …) leaves 0 to the locale's plural rule, which
    // returns the segment untrimmed — a leading space. Zero has its own {0} segment.
    app()->setLocale('sk');
    app(CertificateProviderManager::class)->extend('array', fn (): ArrayProvider => new ArrayProvider);

    $this->artisan('certificates:prune', ['--days' => '30'])
        ->expectsOutput('Odstránilo sa 0 certifikátov.')
        ->assertExitCode(0);

    $this->artisan('certificates:sync', ['--driver' => 'array'])
        ->expectsOutput('Od poskytovateľa array sa synchronizovalo 0 certifikátov.')
        ->assertExitCode(0);

    $certificate = Certificate::factory()->create([
        'domain' => 'today.example.com',
        'status' => CertificateStatus::Issued,
        'expires_at' => CarbonImmutable::now()->addHours(2),
    ]);

    expect((new CertificateExpiryCheck(certificateId: $certificate->id))->check()->message)
        ->toBe('Platnosť certifikátu pre today.example.com vyprší o 0 dní – obnovte ho, aby nedošlo k výpadku.')
        ->and((new CertificateExpiryCheck(certificateId: $certificate->id, warningDays: -1, criticalDays: -1))->check()->message)
        ->toBe('Certifikát pre today.example.com je v poriadku (platnosť vyprší o 0 dní).');
});

it('keeps a published override without plural forms working', function (): void {
    // A host's lang/vendor/certificates/<locale>/messages.php written before the lines
    // gained plural forms has a single, pipe-less line — it must still render whole.
    app('translator')->addLines(['messages.commands.pruned' => 'Removed :count stale records.'], 'en', 'certificates');
    Certificate::factory()->count(3)->expired()->create(['updated_at' => CarbonImmutable::now()->subDays(60)]);

    $this->artisan('certificates:prune', ['--days' => '30'])
        ->expectsOutputToContain('Removed 3 stale records.')
        ->assertExitCode(0);

    $certificate = expiringCertificates(1, 3);
    app('translator')->addLines(['messages.alerts.critical' => ':domain: :days days left.'], 'en', 'certificates');

    expect((new CertificateExpiryCheck(certificateId: $certificate->id))->check()->message)
        ->toBe($certificate->domain.': 3 days left.');
});
