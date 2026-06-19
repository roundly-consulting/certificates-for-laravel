<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use RoundlyConsulting\Certificates\Contracts\CertificateProvider;
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
final class KubernetesProvider implements CertificateProvider, ReportsCertificateStatus
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

        if ($response->failed()) {
            throw KubernetesApiException::fromResponse('reading certificate status', $response);
        }

        /** @var array<string, mixed> $body */
        $body = $response->json();

        /** @var list<array<string, mixed>> $conditions */
        $conditions = $body['status']['conditions'] ?? [];

        $ready = null;

        foreach ($conditions as $condition) {
            if (($condition['type'] ?? null) === 'Ready') {
                $ready = $condition;

                break;
            }
        }

        $status = match (true) {
            ($ready['status'] ?? null) === 'True' => CertificateStatus::Issued,
            $ready !== null => CertificateStatus::Failed,
            default => CertificateStatus::Pending,
        };

        /** @var string|null $notAfter */
        $notAfter = $body['status']['notAfter'] ?? null;

        return new CertificateStatusReport(
            status: $status,
            expiresAt: $notAfter !== null ? CarbonImmutable::parse($notAfter) : null,
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
        $ingress = $this->fetchIngress();

        $schema = $ingress ?? $this->baseIngressSchema();

        /** @var list<array{hosts?: list<string>, secretName?: string}> $tls */
        $tls = $schema['spec']['tls'] ?? [];

        foreach ($tls as $entry) {
            if (in_array($domain, $entry['hosts'] ?? [], true)) {
                // The Ingress already routes this domain; nothing to do.
                return;
            }
        }

        /** @var list<array<string, mixed>> $rules */
        $rules = $schema['spec']['rules'] ?? [];

        $tls[] = ['hosts' => [$domain], 'secretName' => $name];
        $rules[] = $this->ruleFor($domain);

        $schema['spec']['tls'] = $tls;
        $schema['spec']['rules'] = $rules;

        $this->applyIngress($name, $schema, $ingress !== null);
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
