<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Facades\Certificates;

/**
 * The Actions → Manager → Facade contract: the docblock matches CertificatesManager
 * exactly, `fake()` installs a real subtype everywhere, and every non-@internal action is
 * reachable from the facade (flat or through the `for()` handle).
 */
it('pins the facade contract', function (): void {
    expect(Certificates::class)
        ->toDocumentItsRoot()
        ->toBeFakeable()
        ->toReachEveryAction(__DIR__.'/../../src/Actions');
});
