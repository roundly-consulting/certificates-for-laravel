<?php

declare(strict_types=1);

/**
 * The secret-safe `about` capture (A).
 *
 * Purchases #13 is the bug this exists for: the fleet's most credential-heavy `about`
 * section was guarded by negative assertions against `app(Kernel::class)->output()`,
 * which returns `''` — every "does not leak" check was vacuous, and the leak was caught
 * only because one positive assertion happened to exist.
 *
 * Certificates is the package that section shape matters most for. Its config holds ACME
 * account key material, CA directory URLs and Kubernetes bearer tokens; its own source
 * comment promises the section reports "presence and shape — never a secret, a path to
 * one, a destination, or a host topology name". Every one of those clauses is asserted
 * here against real `about` output rather than trusted.
 */
it('reports presence and shape without leaking a credential, a path, or a topology name', function (): void {
    config()->set('certificates.connection', 'pgsql-certificates');
    config()->set('certificates.renewal.queue', 'certificates-renewals');
    config()->set('certificates.alerts.notifiable', 'App\\Models\\SecurityTeam');
    config()->set('certificates.alerts.register_check', true);
    config()->set('certificates.drivers.kubernetes.base_url', 'https://k8s-prod.internal:6443');
    config()->set('certificates.drivers.kubernetes.token', 'eyJhbGciOiJSUzI1NiIsImtpZCI6-live');
    config()->set('certificates.drivers.kubernetes.ca_path', '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt');
    config()->set('certificates.drivers.kubernetes.namespace', 'production-apps');
    config()->set('certificates.drivers.acme.directory', 'https://acme-v02.api.letsencrypt.org/directory');
    config()->set('certificates.drivers.acme.contact', 'security@example.com');
    config()->set('certificates.drivers.acme.account.key_path', 'acme/prod-account.pem');
    config()->set('certificates.status_cache.store', 'redis-certificates');

    expect('certificates')->toLeakNoSecrets(
        secrets: [
            // A bearer token is root on the cluster.
            'eyJhbGciOiJSUzI1NiIsImtpZCI6-live',
            // A path to key material is a map to the secret, which the comment bans too.
            '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt',
            'acme/prod-account.pem',
            // Destinations and host topology names.
            'https://k8s-prod.internal:6443',
            'https://acme-v02.api.letsencrypt.org/directory',
            'security@example.com',
            'production-apps',
            'pgsql-certificates',
            'certificates-renewals',
            'redis-certificates',
            'App\\Models\\SecurityTeam',
        ],
        mustRender: [
            'Driver',
            'Model',
            'Table',
            'Connection',
            'Kubernetes API',
            'Kubernetes token',
            'ACME directory',
            'ACME account key',
            'Expiry check',
            // The positive proof the presence lines are REPORTING rather than silently
            // empty. Everything above is configured, so each must read SET — without
            // this, a section that rendered nothing would satisfy the leak half.
            'SET',
            'REGISTERED',
            // The model name is deliberately rendered (class_basename, not the FQCN).
            'Certificate',
        ],
    );
});

/**
 * The counterpart: an unconfigured driver must say so rather than go blank. A presence
 * line that renders empty is indistinguishable from one that broke — and this is the
 * state a host most needs `about` to be legible in.
 */
it('reports the absent markers rather than blank lines when nothing is configured', function (): void {
    config()->set('certificates.connection', null);
    config()->set('certificates.drivers.kubernetes.base_url', null);
    config()->set('certificates.drivers.kubernetes.ca_path', null);
    config()->set('certificates.drivers.acme.directory', null);
    config()->set('certificates.alerts.register_check', false);

    expect('certificates')->toLeakNoSecrets(
        secrets: ['eyJhbGciOiJSUzI1NiIsImtpZCI6-live', 'https://k8s-prod.internal:6443'],
        mustRender: [
            'Connection',
            'DEFAULT',
            'Kubernetes API',
            'MISSING',
            'Kubernetes CA',
            'UNVERIFIED',
            'Expiry check',
            'OFF',
        ],
    );
});
