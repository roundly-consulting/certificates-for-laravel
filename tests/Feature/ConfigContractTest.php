<?php

declare(strict_types=1);

/**
 * The config-key contract certificates never had, pinned in both directions.
 *
 *  - forward — every key the code reads is shipped. This is shops #18, whose whole
 *    store-credit feature read `shops.payments.*` while the file shipped `payment.*`;
 *    330 tests stayed green because the suite set the same wrong key.
 *  - reverse — every shipped leaf is read. A documented key nothing reads is dead
 *    config that lies to the host: media #27's `max_file_size` cap that never applied.
 */
it('ships exactly the config keys it reads', function (): void {
    expect(__DIR__.'/../../config/certificates.php')->toSatisfyConfigContract([__DIR__.'/../../src', __DIR__.'/../../database'], [
        // Several real reads never appear as a `config(` token: `certificates.model`
        // goes through the toolkit's `ModelResolver::for('certificates.model', …)` seam
        // that drives the whole model swap, and `CertificateProviderManager` takes an injected
        // `Illuminate\Contracts\Config\Repository`. The prefix is what makes those
        // literals visible to the scraper.
        //
        // Deliberately NO `excludeFromReverse` for the provider. The testing README's own
        // example excludes the service provider on the grounds that "a render is not a
        // read" — but this provider's `contributesToAbout()` closure calls
        // `config('certificates.…')` for real and `bindFromConfig()` does real reads too,
        // so excluding it would discard the only reader of several bound keys and weaken
        // the reverse direction for nothing.
        'extraReadPrefixes' => ['certificates.'],

        // Each driver factory takes its whole `drivers.<name>` section and reads it by
        // offset rather than through a dozen `config()` calls, so those offsets ARE the
        // reads and mapping the variables is what makes them visible.
        //
        // This is strictly better than `allowUnread`, which would assert a falsehood
        // about twenty live keys — every one of them steers a real driver decision.
        // Getting here needed a rename in CertificateProviderManager: all three factories read
        // their section into a variable called `$config`, and `sectionVariables` is
        // FILE-scoped, so one mapping would have applied `$filesystem['disk']` under the
        // kubernetes prefix and invented reads of keys that do not exist. Naming each
        // section after its driver is a pure local rename with no behaviour change — and
        // `$kubernetes['base_url']` reads better than `$config['base_url']` anyway.
        'sectionVariables' => [
            'CertificateProviderManager.php' => [
                '$kubernetes' => 'certificates.drivers.kubernetes',
                '$filesystem' => 'certificates.drivers.filesystem',
                '$acme' => 'certificates.drivers.acme',
                '$accountConfig' => 'certificates.drivers.acme.account',
                '$storeConfig' => 'certificates.drivers.acme.store',
                '$pollConfig' => 'certificates.drivers.acme.poll',
                '$http' => 'certificates.drivers.acme.http',
            ],

            // The provider builds the default store and challenge solver from two acme
            // sub-sections in two closures — the same file-scoped collision, same fix.
            'CertificatesServiceProvider.php' => [
                '$store' => 'certificates.drivers.acme.store',
                '$http' => 'certificates.drivers.acme.http',
            ],
        ],

        // The two driver sections that are genuinely read by nothing, and correctly so:
        // `createArrayDriver()` and `createNullDriver()` take no configuration at all —
        // Laravel's Manager dispatches on the driver NAME, never on the section's
        // presence. They ship as empty markers documenting that the drivers exist.
        //
        // Allowed rather than deleted: removing a shipped config key is a config change,
        // not test machinery. `allowUnread` is rot-proof, so if either driver ever grows
        // a real option this entry fails as stale rather than hiding it.
        'allowUnread' => [
            'certificates.drivers.array',
            'certificates.drivers.null',
        ],
    ]);
});
