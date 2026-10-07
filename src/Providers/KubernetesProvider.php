<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
use RoundlyConsulting\Certificates\Contracts\ProvisionsMultipleDomains;
use RoundlyConsulting\Certificates\Contracts\ReportsCertificateStatus;
use RoundlyConsulting\Certificates\DataTransferObjects\CertificateStatusReport;
use RoundlyConsulting\Certificates\Enums\CertificateStatus;
use RoundlyConsulting\Certificates\Exceptions\KubernetesApiException;
use RoundlyConsulting\Certificates\ValueObjects\RemoteCertificate;
use SensitiveParameter;

/**
 * Talks to the Kubernetes API directly over HTTP (via Laravel's HTTP client)
 * to list cert-manager Certificates and to provision TLS by patching an
 * Ingress with cert-manager annotations and TLS hosts.
 *
 * No third-party Kubernetes SDK is used: authentication is the standard
 * in-cluster service-account token + CA bundle, all configurable so the
 * provider also works from outside the cluster.
 */
final class KubernetesProvider implements CertificateProvider, ProvisionsMultipleDomains, ReportsCertificateStatus
{
    /**
     * @param  string  $baseUrl  Kubernetes API server base URL (e.g. https://kubernetes.default.svc)
     * @param  string  $token  Bearer token for the service account
     * @param  string  $namespace  Namespace the Ingress and certificates live in
     * @param  string  $ingressName  Name of the Ingress to manage
     * @param  string|null  $serviceName  Backend service routed for newly added hosts
     * @param  int  $servicePort  Backend service port
     * @param  string  $issuer  cert-manager issuer name
     * @param  string  $issuerKind  cert-manager issuer kind (Issuer or ClusterIssuer)
     * @param  string  $ingressClass  Ingress class annotation value
     * @param  string|bool  $verify  CA bundle path, or false to disable TLS verification
     */
    public function __construct(
        private readonly string $baseUrl,
        #[SensitiveParameter] private readonly string $token,
        private readonly string $namespace,
        private readonly string $ingressName,
        private readonly ?string $serviceName,
        private readonly int $servicePort,
        private readonly string $issuer,
        private readonly string $issuerKind,
        private readonly string $ingressClass,
        private readonly string|bool $verify,
    ) {}

    public function get(): Collection
    {
        $response = $this->request()->get($this->certificatesPath());

        if ($response->failed()) {
            throw KubernetesApiException::fromResponse('listing certificates', $response);
        }

        /** @var list<array<string, mixed>> $items */
        $items = $response->json('items', []);

        return Collection::make($items)
            ->map(fn (array $item): ?RemoteCertificate => $this->mapCertificate($item))
            ->filter()
            ->values();
    }

    public function status(string $name, string $domain): CertificateStatusReport
    {
        $response = $this->request()->get($this->certificatesPath().'/'.$name);

        // ingress-shim creates the Certificate moments after the Ingress is patched, so on a
        // first issuance it is routinely absent: not issued yet, rather than an error.
        if ($response->status() === 404) {
            return new CertificateStatusReport(status: CertificateStatus::Pending);
        }

        if ($response->failed()) {
            throw KubernetesApiException::fromResponse('reading certificate status', $response);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        /** @var array<string, mixed> $state */
        $state = is_array($body['status'] ?? null) ? $body['status'] : [];

        /** @var string|null $notAfter */
        $notAfter = $state['notAfter'] ?? null;
        $expiresAt = $notAfter !== null ? CarbonImmutable::parse($notAfter) : null;

        return new CertificateStatusReport(
            status: $this->statusFrom($state, $expiresAt),
            expiresAt: $expiresAt,
            issuer: isset($body['spec']['issuerRef']['name']) ? (string) $body['spec']['issuerRef']['name'] : null,
        );
    }

    public function exists(string $name, string $domain): bool
    {
        $response = $this->request()->get($this->certificatesPath().'/'.$name);

        if ($response->status() === 404) {
            return false;
        }

        if ($response->failed()) {
            throw KubernetesApiException::fromResponse('checking certificate existence', $response);
        }

        return true;
    }

    public function generate(string $name, string $domain): void
    {
        $this->generateMany($name, [$domain]);
    }

    /**
     * @param  list<string>  $domains
     */
    public function generateMany(string $name, array $domains): void
    {
        $ingress = $this->fetchIngress();

        $schema = $this->withCertificate($ingress ?? $this->baseIngressSchema(), $name, $domains);

        if ($schema === null) {
            // The Ingress already secures and routes exactly these hosts; nothing to do.
            return;
        }

        $this->applyIngress($name, $schema, $ingress !== null);
    }

    /**
     * The Ingress with a TLS entry for `$name` covering exactly `$domains`, and a rule for each
     * host; null when it already has both. A re-issue with a changed SAN set rewrites the
     * existing entry's hosts — the cluster must secure what the registry records. Rules are
     * only ever added: a host dropped from the certificate may still be routed on purpose.
     *
     * @param  array<string, mixed>  $schema
     * @param  list<string>  $domains
     * @return array<string, mixed>|null
     */
    private function withCertificate(array $schema, string $name, array $domains): ?array
    {
        /** @var list<array{hosts?: list<string>, secretName?: string}> $tls */
        $tls = $schema['spec']['tls'] ?? [];

        /** @var list<array<string, mixed>> $rules */
        $rules = $schema['spec']['rules'] ?? [];

        $existing = null;

        foreach ($tls as $index => $entry) {
            if (($entry['secretName'] ?? null) === $name) {
                $existing = $index;

                break;
            }
        }

        if ($existing === null) {
            $tls[] = ['hosts' => $domains, 'secretName' => $name];

            foreach ($domains as $domain) {
                $rules[] = $this->ruleFor($domain);
            }
        } else {
            $hosts = $tls[$existing]['hosts'] ?? [];
            $routed = array_map(static fn (array $rule): mixed => $rule['host'] ?? null, $rules);
            $missing = array_values(array_filter($domains, static fn (string $domain): bool => ! in_array($domain, $routed, true)));

            if ($this->sameHosts($hosts, $domains) && $missing === []) {
                return null;
            }

            $tls[$existing]['hosts'] = $domains;

            foreach ($missing as $domain) {
                $rules[] = $this->ruleFor($domain);
            }
        }

        $schema['spec']['tls'] = $tls;
        $schema['spec']['rules'] = $rules;

        return $schema;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function sameHosts(array $a, array $b): bool
    {
        $a = array_values(array_unique($a));
        $b = array_values(array_unique($b));
        sort($a);
        sort($b);

        return $a === $b;
    }

    /**
     * Map cert-manager's conditions onto the registry lifecycle. `Ready=False` alone is not
     * a failure — it is also what cert-manager reports while it is still issuing — so only
     * a failed issuance attempt (`Issuing=False` with reason `Failed`, or a recorded
     * `lastFailureTime`) is Failed; anything else not yet Ready is Pending.
     *
     * @param  array<string, mixed>  $state
     */
    private function statusFrom(array $state, ?CarbonImmutable $expiresAt): CertificateStatus
    {
        /** @var list<array<string, mixed>> $conditions */
        $conditions = is_array($state['conditions'] ?? null) ? $state['conditions'] : [];

        $ready = $this->condition($conditions, 'Ready');
        $issuing = $this->condition($conditions, 'Issuing');
        $lapsed = $expiresAt !== null && $expiresAt->isPast();

        return match (true) {
            ($ready['status'] ?? null) === 'True' => $lapsed ? CertificateStatus::Expired : CertificateStatus::Issued,
            ($issuing['status'] ?? null) === 'True' => CertificateStatus::Pending,
            ($issuing['reason'] ?? null) === 'Failed', isset($state['lastFailureTime']) => CertificateStatus::Failed,
            $lapsed => CertificateStatus::Expired,
            default => CertificateStatus::Pending,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return array<string, mixed>|null
     */
    private function condition(array $conditions, string $type): ?array
    {
        foreach ($conditions as $condition) {
            if (($condition['type'] ?? null) === $type) {
                return $condition;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function mapCertificate(array $item): ?RemoteCertificate
    {
        /** @var string|null $name */
        $name = $item['metadata']['name'] ?? null;

        /** @var list<string> $dnsNames */
        $dnsNames = $item['spec']['dnsNames'] ?? [];
        $domain = $dnsNames[0] ?? ($item['spec']['commonName'] ?? null);

        if ($name === null || $domain === null) {
            return null;
        }

        return new RemoteCertificate(name: $name, domain: (string) $domain);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchIngress(): ?array
    {
        $response = $this->request()->get($this->ingressPath().'/'.$this->ingressName);

        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            throw KubernetesApiException::fromResponse('fetching ingress', $response);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        return $body;
    }

    /**
     * @param  array<string, mixed>  $schema
     */
    private function applyIngress(string $name, array $schema, bool $exists): void
    {
        $response = $exists
            ? $this->request()
                ->withHeaders(['Content-Type' => 'application/merge-patch+json'])
                ->patch($this->ingressPath().'/'.$this->ingressName, $schema)
            : $this->request()->post($this->ingressPath(), $schema);

        if ($response->failed()) {
            throw KubernetesApiException::fromResponse('applying ingress for '.$name, $response);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function baseIngressSchema(): array
    {
        return [
            'apiVersion' => 'networking.k8s.io/v1',
            'kind' => 'Ingress',
            'metadata' => [
                'name' => $this->ingressName,
                'namespace' => $this->namespace,
                'annotations' => [
                    'kubernetes.io/ingress.class' => $this->ingressClass,
                    'cert-manager.io/'.($this->issuerKind === 'ClusterIssuer' ? 'cluster-issuer' : 'issuer') => $this->issuer,
                ],
            ],
            'spec' => [
                'tls' => [],
                'rules' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ruleFor(string $domain): array
    {
        return [
            'host' => $domain,
            'http' => [
                'paths' => [
                    [
                        'path' => '/',
                        'pathType' => 'Prefix',
                        'backend' => [
                            'service' => [
                                'name' => $this->serviceName,
                                'port' => ['number' => $this->servicePort],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withToken($this->token)
            ->acceptJson()
            ->withOptions(['verify' => $this->verify]);
    }

    private function certificatesPath(): string
    {
        return sprintf('/apis/cert-manager.io/v1/namespaces/%s/certificates', $this->namespace);
    }

    private function ingressPath(): string
    {
        return sprintf('/apis/networking.k8s.io/v1/namespaces/%s/ingresses', $this->namespace);
    }
}
