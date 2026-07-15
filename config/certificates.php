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
    | Database connection
    |--------------------------------------------------------------------------
    |
    | The database connection the certificate registry uses by default. Leave
    | null to use the model's default connection. Multi-tenant apps can target
    | a specific connection per call with Certificates::on('tenant').
    |
    */
    'connection' => env('CERTIFICATES_DB_CONNECTION'),

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
    | Status caching
    |--------------------------------------------------------------------------
    |
    | Provider status() lookups can be cached to avoid a backend round-trip on
    | every call. Set "store" to a specific cache store (null = default), and
    | "ttl" to the cache lifetime in seconds. Bypass the cache per call with
    | the builder's ->fresh() method or statusReport(fresh: true).
    |
    */
    'status_cache' => [
        'enabled' => (bool) env('CERTIFICATES_STATUS_CACHE', true),
        'store' => env('CERTIFICATES_STATUS_CACHE_STORE'),
        'ttl' => (int) env('CERTIFICATES_STATUS_CACHE_TTL', 300),
    ],

    /*
    |--------------------------------------------------------------------------
    | Expiry monitoring via alerts-for-laravel
    |--------------------------------------------------------------------------
    |
    | Certificate expiry is routed through the alerts health-check engine, so it
    | inherits alert dedup/throttle, escalation, silence windows, history and the
    | /health surface. certificates:check runs the CertificateExpiryCheck for
    | every expiring cert against a resolved notifiable when "enabled" is true.
    |
    | "notifiable" is an optional FQCN resolved from the container; it takes
    | precedence over each certificate's own certifiable owner. "thresholds"
    | drive the check's warning/critical banding (warning >= critical). Set
    | "register_check" to register a registry-wide CertificateExpiryCheck with
    | the alerts registry at boot for a single global expiry signal.
    |
    */
    'alerts' => [
        'enabled' => (bool) env('CERTIFICATES_ALERTS', false),
        'notifiable' => env('CERTIFICATES_ALERTS_NOTIFIABLE'),
        'thresholds' => [
            'warning_days' => (int) env('CERTIFICATES_ALERTS_WARNING_DAYS', 30),
            'critical_days' => (int) env('CERTIFICATES_ALERTS_CRITICAL_DAYS', 7),
        ],
        'channels' => ['mail'],
        'register_check' => (bool) env('CERTIFICATES_ALERTS_REGISTER_CHECK', false),
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

        // Native ACME v2 (e.g. Let's Encrypt) provider in pure PHP.
        'acme' => [
            'directory' => env('CERTIFICATES_ACME_DIRECTORY', 'https://acme-v02.api.letsencrypt.org/directory'),
            // Staging: https://acme-staging-v02.api.letsencrypt.org/directory

            // Contact email; sent to the CA as a mailto: contact.
            'contact' => env('CERTIFICATES_ACME_CONTACT'),

            'account' => [
                // "EC" generates a P-256 key; "RSA" a 2048-bit one. An existing
                // account key on the disk is used as-is: EC P-256 and P-384 are
                // both accepted, and each signs under its own alg (ES256/ES384).
                'key_type' => env('CERTIFICATES_ACME_KEY_TYPE', 'EC'),
                'disk' => env('CERTIFICATES_ACME_ACCOUNT_DISK', 'local'),
                'key_path' => env('CERTIFICATES_ACME_ACCOUNT_KEY', 'acme/account.pem'),
                'auto_register' => (bool) env('CERTIFICATES_ACME_AUTO_REGISTER', true),
            ],

            // "http-01" (shipped) or "dns-01" (provide your own solver).
            'challenge_type' => env('CERTIFICATES_ACME_CHALLENGE', 'http-01'),

            // Custom AcmeChallengeSolver FQCN; null uses the HTTP-01 solver.
            'solver' => env('CERTIFICATES_ACME_SOLVER'),

            'http' => [
                'disk' => env('CERTIFICATES_ACME_HTTP_DISK', 'local'),
                'path' => env('CERTIFICATES_ACME_HTTP_PATH', 'acme-challenge'),
            ],

            // Where issued material (leaf, key, chain) is stored.
            'store' => [
                'disk' => env('CERTIFICATES_ACME_STORE_DISK', 'local'),
                'path' => env('CERTIFICATES_ACME_STORE_PATH', 'certificates'),
            ],

            // Validation/finalization polling bounds.
            'poll' => [
                'attempts' => (int) env('CERTIFICATES_ACME_POLL_ATTEMPTS', 30),
                'seconds' => (int) env('CERTIFICATES_ACME_POLL_SECONDS', 2),
            ],

            // CA bundle path, or false to disable TLS verification (not recommended).
            'verify' => env('CERTIFICATES_ACME_VERIFY', true),
        ],

        // Stores/reads PEM material on a Storage disk; can self-sign for dev.
        'filesystem' => [
            'disk' => env('CERTIFICATES_FS_DISK', 'local'),
            'path' => env('CERTIFICATES_FS_PATH', 'certificates'),
            'self_signed' => (bool) env('CERTIFICATES_FS_SELF_SIGNED', false),
            'self_signed_days' => (int) env('CERTIFICATES_FS_SELF_SIGNED_DAYS', 90),
        ],

        // No-op driver for local/dev where no certificate backend exists.
        'null' => [],

        // In-memory driver, primarily backing the test fake.
        'array' => [],
    ],
];
