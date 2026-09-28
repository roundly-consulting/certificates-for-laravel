<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use RoundlyConsulting\Certificates\CertificatesManager;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\DataTransferObjects\IssueCertificateData;
use RoundlyConsulting\Certificates\DataTransferObjects\RenewalFailure;
use RoundlyConsulting\Certificates\DataTransferObjects\RenewalReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;
use RoundlyConsulting\Certificates\Exceptions\InvalidDomainException;
use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Certificates\Providers\ArrayProvider;
use RoundlyConsulting\Certificates\Rules\ValidDomain;
use RoundlyConsulting\Certificates\Support\CertificateModel;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;
use RuntimeException;
use Throwable;

/**
 * In-memory stand-in for CertificatesManager, installed by `Certificates::fake()`.
 *
 * A real subtype (it calls the parent constructor), so constructor-injected managers get
 * it too. It never touches a provider, the registry, the queue or the event bus:
 * certificates live in memory, every driver is an ArrayProvider, and every mutating call
 * — through the facade, an injected manager, the `for()` handle or the HasCertificates
 * trait — is recorded for the `assert*` methods. Invalid domains, illegal lifecycle
 * transitions (renewLater() included) and unknown domains still throw, exactly as they do
 * for real; `failRenewalOf()` makes chosen renewals fail the way a rejecting CA would.
 */
final class CertificatesFake extends CertificatesManager
{
    /** @var list<string> */
    private array $requested = [];

    /** @var list<string> */
    private array $issued = [];

    /** @var list<string> */
    private array $failed = [];

    /** @var list<string> */
    private array $renewed = [];

    /** @var list<string> */
    private array $renewedLater = [];

    /** @var list<int|null> */
    private array $renewedDue = [];

    /** @var list<string> */
    private array $failingRenewals = [];

    /** @var list<string> */
    private array $renewalFailures = [];

    /** @var list<array{0: string, 1: string|null}> */
    private array $revoked = [];

    /** @var list<string> */
    private array $expired = [];

    /** @var list<string|null> */
    private array $synced = [];

    /** @var list<int> */
    private array $pruned = [];

    /** @var array<string, Certificate> */
    private array $store = [];

    /** @var array<string, ArrayProvider> */
    private array $drivers = [];

    /**
     * Put certificates in the in-memory registry without recording an issuance, so a
     * test can renew, revoke or expire something it did not issue through the fake.
     */
    public function seed(Certificate ...$certificates): static
    {
        foreach ($certificates as $certificate) {
            $this->store[$this->key($certificate->domain)] = $certificate;
        }

        return $this;
    }

    /**
     * Make renewals of these domains fail: the certificate moves to Failed and renew()
     * throws, so renewDue() reports it under `failed` and carries on with the rest.
     */
    public function failRenewalOf(string ...$domains): static
    {
        $this->failingRenewals = [...$this->failingRenewals, ...array_map($this->key(...), array_values($domains))];

        return $this;
    }

    public function on(?string $connection): static
    {
        return $this;
    }

    public function get(): Collection
    {
        return Collection::make(array_map(
            fn (Certificate $certificate): RemoteCertificate => new RemoteCertificate($certificate->name, $certificate->domain),
            array_values($this->store),
        ));
    }

    public function exists(string $domain): bool
    {
        return isset($this->store[$this->key($domain)]);
    }

    public function generate(string $domain): bool
    {
        $this->issue(IssueCertificateData::make($domain));

        return true;
    }

    public function issue(IssueCertificateData $data): Certificate
    {
        $domains = array_values(array_unique(array_map($this->key(...), $data->allDomains())));
        $rule = new ValidDomain;

        foreach ($domains as $candidate) {
            if (! $rule->passes($candidate)) {
                throw InvalidDomainException::forDomain($candidate);
            }
        }

        $domain = $domains[0];

        $this->requested[] = $domain;

        $model = CertificateModel::class();
        $certificate = new $model;
        $certificate->forceFill([
            'name' => $this->certificateName($domain),
            'domain' => $domain,
            'domains' => count($domains) > 1 ? $domains : null,
            'driver' => $data->driver ?? 'array',
            'status' => CertificateStatus::Issued,
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays($data->validForDays ?? 90),
        ]);

        if ($data->owner !== null) {
            $certificate->certifiable()->associate($data->owner);
        }

        $this->store[$domain] = $certificate;
        $this->issued[] = $domain;

        return $certificate;
    }

    public function issueIfMissing(string $domain): Certificate
    {
        $existing = $this->store[$this->key($domain)] ?? null;

        if ($existing !== null && $existing->isActive()) {
            return $existing;
        }

        return $this->issue(IssueCertificateData::make($domain));
    }

    public function find(string $domain, ?string $driver = null): ?Certificate
    {
        $certificate = $this->store[$this->key($domain)] ?? null;

        if ($certificate === null || ($driver !== null && $certificate->driver !== $driver)) {
            return null;
        }

        return $certificate;
    }

    public function status(string $domain): ?CertificateStatus
    {
        return $this->find($domain)?->status;
    }

    public function statusReport(string $domain, ?string $driver = null, bool $fresh = false): ?CertificateStatusReport
    {
        $certificate = $this->find($domain, $driver);

        if ($certificate === null) {
            return null;
        }

        return new CertificateStatusReport(
            status: $certificate->status,
            expiresAt: $certificate->expires_at,
        );
    }

    public function expiring(?int $days = null, ?string $driver = null): EloquentCollection
    {
        $days ??= (int) config('certificates.renewal.threshold_days', 21);
        $until = CarbonImmutable::now()->addDays($days);

        $due = array_filter(
            $this->store,
            static fn (Certificate $certificate): bool => ($certificate->status->isActive() || $certificate->status === CertificateStatus::Failed)
                && $certificate->expires_at !== null
                && $certificate->expires_at->between(CarbonImmutable::now(), $until)
                && ($driver === null || $certificate->driver === $driver),
        );

        usort($due, static fn (Certificate $a, Certificate $b): int => $a->expires_at <=> $b->expires_at);

        return new EloquentCollection($due);
    }

    public function renew(Certificate|string $certificate): Certificate
    {
        $certificate = $this->transition($certificate, CertificateStatus::Renewing);

        if (in_array($certificate->domain, $this->failingRenewals, true)) {
            $reason = "Renewal of [{$certificate->domain}] failed (CertificatesFake::failRenewalOf).";

            $certificate->forceFill(['status' => CertificateStatus::Failed, 'last_error' => $reason]);
            $this->renewalFailures[] = $certificate->domain;

            throw new RuntimeException($reason);
        }

        $certificate->forceFill([
            'status' => CertificateStatus::Renewed,
            'expires_at' => CarbonImmutable::now()->addDays(90),
            'last_renewed_at' => CarbonImmutable::now(),
            'last_error' => null,
        ]);

        $this->renewed[] = $certificate->domain;

        return $certificate;
    }

    public function renewLater(Certificate|string $certificate): Certificate
    {
        $certificate = $this->transition($certificate, CertificateStatus::Renewing);

        $this->renewedLater[] = $certificate->domain;

        return $certificate;
    }

    public function renewDue(?int $thresholdDays = null, bool $queue = false): RenewalReport
    {
        $this->renewedDue[] = $thresholdDays;

        $renewed = [];
        $queued = [];
        $failed = [];

        foreach ($this->expiring($thresholdDays) as $certificate) {
            if ($queue) {
                $queued[] = $this->renewLater($certificate);

                continue;
            }

            try {
                $renewed[] = $this->renew($certificate);
            } catch (Throwable $e) {
                $failed[] = new RenewalFailure($certificate, $e);
            }
        }

        return new RenewalReport($renewed, $queued, $failed);
    }

    public function revoke(Certificate|string $certificate, ?string $reason = null): Certificate
    {
        $certificate = $this->transition($certificate, CertificateStatus::Revoked);

        $certificate->forceFill(['status' => CertificateStatus::Revoked, 'last_error' => $reason]);

        $this->revoked[] = [$certificate->domain, $reason];

        return $certificate;
    }

    public function expire(Certificate|string $certificate): Certificate
    {
        $certificate = $this->transition($certificate, CertificateStatus::Expired);

        $certificate->forceFill(['status' => CertificateStatus::Expired]);

        $this->expired[] = $certificate->domain;

        return $certificate;
    }

    public function sync(?string $driver = null): int
    {
        $this->synced[] = $driver;

        return 0;
    }

    public function prune(int $days = 30, CertificateStatus|string|null $status = null): int
    {
        $this->pruned[] = $days;

        return 0;
    }

    /**
     * An in-memory ArrayProvider per driver name, so code that reaches for a driver
     * under the fake never talks to a real backend.
     */
    public function driver(?string $name = null): CertificateProvider
    {
        $name ??= $this->providers->getDefaultDriver();

        return $this->drivers[$name] ??= new ArrayProvider;
    }

    /**
     * Record an intentionally-failed issuance (for tests exercising failure).
     */
    public function recordFailure(string $domain): void
    {
        $this->requested[] = $this->key($domain);
        $this->failed[] = $this->key($domain);
    }

    public function assertIssued(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->issued, "Expected a certificate to be issued for [{$domain}].");
    }

    public function assertNotIssued(string $domain): void
    {
        Assert::assertNotContains($this->key($domain), $this->issued, "Expected no certificate to be issued for [{$domain}].");
    }

    public function assertIssuedCount(int $count): void
    {
        Assert::assertCount($count, $this->issued, "Expected [{$count}] certificate(s) to be issued.");
    }

    public function assertNothingIssued(): void
    {
        Assert::assertSame([], $this->issued, 'Expected no certificate to be issued.');
    }

    public function assertRequested(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->requested, "Expected a certificate to be requested for [{$domain}].");
    }

    public function assertFailed(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->failed, "Expected a certificate to fail for [{$domain}].");
    }

    public function assertRenewed(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->renewed, "Expected the certificate for [{$domain}] to be renewed.");
    }

    public function assertNotRenewed(string $domain): void
    {
        Assert::assertNotContains($this->key($domain), $this->renewed, "Expected the certificate for [{$domain}] not to be renewed.");
    }

    public function assertNothingRenewed(): void
    {
        Assert::assertSame([], $this->renewed, 'Expected no certificate to be renewed.');
    }

    public function assertRenewedLater(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->renewedLater, "Expected a renewal to be queued for [{$domain}].");
    }

    public function assertNothingRenewedLater(): void
    {
        Assert::assertSame([], $this->renewedLater, 'Expected no renewal to be queued.');
    }

    /**
     * @param  int|null  $thresholdDays  when given, a run with exactly this threshold
     */
    public function assertRenewedDue(?int $thresholdDays = null): void
    {
        Assert::assertTrue(
            $this->renewedDue !== [] && ($thresholdDays === null || in_array($thresholdDays, $this->renewedDue, true)),
            $thresholdDays === null
                ? 'Expected due certificates to be renewed.'
                : "Expected due certificates to be renewed with a [{$thresholdDays}]-day threshold.",
        );
    }

    public function assertNothingRenewedDue(): void
    {
        Assert::assertSame([], $this->renewedDue, 'Expected no renew-due run.');
    }

    public function assertRenewalFailed(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->renewalFailures, "Expected the renewal of [{$domain}] to fail.");
    }

    public function assertNoRenewalFailures(): void
    {
        Assert::assertSame([], $this->renewalFailures, 'Expected no renewal to fail.');
    }

    /**
     * @param  string|null  $reason  when given, the revocation must carry exactly this reason
     */
    public function assertRevoked(string $domain, ?string $reason = null): void
    {
        $matching = array_filter(
            $this->revoked,
            fn (array $revocation): bool => $revocation[0] === $this->key($domain) && ($reason === null || $revocation[1] === $reason),
        );

        Assert::assertNotSame([], $matching, $reason === null
            ? "Expected the certificate for [{$domain}] to be revoked."
            : "Expected the certificate for [{$domain}] to be revoked with reason [{$reason}].");
    }

    public function assertNotRevoked(string $domain): void
    {
        Assert::assertNotContains($this->key($domain), array_column($this->revoked, 0), "Expected the certificate for [{$domain}] not to be revoked.");
    }

    public function assertNothingRevoked(): void
    {
        Assert::assertSame([], $this->revoked, 'Expected no certificate to be revoked.');
    }

    public function assertExpired(string $domain): void
    {
        Assert::assertContains($this->key($domain), $this->expired, "Expected the certificate for [{$domain}] to be marked expired.");
    }

    public function assertNothingExpired(): void
    {
        Assert::assertSame([], $this->expired, 'Expected no certificate to be marked expired.');
    }

    /**
     * @param  string|null  $driver  when given, a sync of exactly this driver (null = any sync)
     */
    public function assertSynced(?string $driver = null): void
    {
        Assert::assertTrue(
            $this->synced !== [] && ($driver === null || in_array($driver, $this->synced, true)),
            $driver === null ? 'Expected a certificate sync.' : "Expected the [{$driver}] driver to be synced.",
        );
    }

    public function assertNothingSynced(): void
    {
        Assert::assertSame([], $this->synced, 'Expected no certificate sync.');
    }

    /**
     * @param  int|null  $days  when given, a prune with exactly this age cut-off
     */
    public function assertPruned(?int $days = null): void
    {
        Assert::assertTrue(
            $this->pruned !== [] && ($days === null || in_array($days, $this->pruned, true)),
            $days === null ? 'Expected a certificate prune.' : "Expected a certificate prune of rows older than [{$days}] day(s).",
        );
    }

    public function assertNothingPruned(): void
    {
        Assert::assertSame([], $this->pruned, 'Expected no certificate prune.');
    }

    /**
     * Hostnames are case-insensitive — the fake keys and records them lowercased, as the
     * real registry does.
     */
    private function key(string $domain): string
    {
        return Str::lower(trim($domain));
    }

    private function transition(Certificate|string $certificate, CertificateStatus $to): Certificate
    {
        $certificate = $this->resolve($certificate);

        if (! $certificate->status->canTransitionTo($to)) {
            throw CertificateException::illegalTransition($certificate->status, $to);
        }

        return $certificate;
    }
}
