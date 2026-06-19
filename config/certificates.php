<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Models\Certificate;

return [
    /*
    |--------------------------------------------------------------------------
    | Default driver
    |--------------------------------------------------------------------------
    |
    | The provider driver used when no explicit driver is requested. Must match
    | a key under "drivers" below (or a driver registered via the manager's
    | extend() method).
    |
    */
    'default' => env('CERTIFICATES_DRIVER', 'kubernetes'),

    /*
    |--------------------------------------------------------------------------
    | Registry model & table
    |--------------------------------------------------------------------------
    |
    | The Eloquent model and table used for the local certificate registry.
    | Override the model to extend behaviour; override the table to avoid a
    | name clash with an existing schema.
    |
    */
    'model' => Certificate::class,
    'table' => 'certificates',

    /*
    |--------------------------------------------------------------------------
    | Renewal
    |--------------------------------------------------------------------------
    |
    | "threshold_days" is how many days before expiry a certificate is treated
    | as "expiring" (and renewed by certificates:renew). "queue" optionally
    | names the queue the renewal job is dispatched onto.
    |
    */
    'renewal' => [
        'threshold_days' => (int) env('CERTIFICATES_RENEW_THRESHOLD_DAYS', 21),
        'queue' => env('CERTIFICATES_RENEW_QUEUE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Certificate name prefix
    |--------------------------------------------------------------------------
    |
    | Prefix used when deriving the deterministic, DNS-safe secret name for a
    | domain (e.g. "example.com" => "generated-tls-example-com").
    |
    */
    'name_prefix' => env('CERTIFICATES_NAME_PREFIX', 'generated-tls-'),

    /*
    |--------------------------------------------------------------------------
    | Generation lock
    |--------------------------------------------------------------------------
    |
    | A cache lock guards against provisioning the same certificate twice
    | concurrently. Configure the lock name and how long it is held.
    |
    */
    'lock' => [
        'name' => env('CERTIFICATES_LOCK_NAME', 'certificates:generate'),
        'locked_for_seconds' => (int) env('CERTIFICATES_LOCK_SECONDS', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    |
    | Configuration for each pluggable certificate provider. The shipped
    | Kubernetes driver talks to the Kubernetes API directly to list
    | cert-manager Certificates and to patch an Ingress with TLS hosts. The
    | "null" driver is a no-op for local/dev, and "array" is an in-memory
    | driver primarily backing the test fake.
    |
    */
    'drivers' => [
        'kubernetes' => [
            // Kubernetes API server base URL.
            'base_url' => env('CERTIFICATES_K8S_BASE_URL', 'https://kubernetes.default.svc'),

            // Bearer token. Leave blank to read it from the token_path file
            // (the default in-cluster service-account token location).
            'token' => env('CERTIFICATES_K8S_TOKEN'),
            'token_path' => env(
                'CERTIFICATES_K8S_TOKEN_PATH',
                '/var/run/secrets/kubernetes.io/serviceaccount/token',
            ),

            // CA bundle path used to verify the API server's TLS certificate.
            // Set to null to disable verification (not recommended).
            'ca_path' => env(
                'CERTIFICATES_K8S_CA_PATH',
                '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt',
            ),

            // Namespace the Ingress and certificates live in.
            'namespace' => env('CERTIFICATES_K8S_NAMESPACE', 'default'),

            // cert-manager issuer to request certificates from.
            'issuer' => env('CERTIFICATES_K8S_ISSUER', 'letsencrypt'),
            'issuer_kind' => env('CERTIFICATES_K8S_ISSUER_KIND', 'ClusterIssuer'),

            // Ingress to manage.
            'ingress' => [
                'name' => env('CERTIFICATES_K8S_INGRESS_NAME'),
                'class' => env('CERTIFICATES_K8S_INGRESS_CLASS', 'nginx'),
            ],

            // Backend service routed for newly added hosts.
            'service' => [
                'name' => env('CERTIFICATES_K8S_SERVICE_NAME'),
                'port' => (int) env('CERTIFICATES_K8S_SERVICE_PORT', 80),
            ],
        ],

        // No-op driver for local/dev where no certificate backend exists.
        'null' => [],

        // In-memory driver, primarily backing the test fake.
        'array' => [],
    ],
];
