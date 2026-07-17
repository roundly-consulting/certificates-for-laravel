<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use RoundlyConsulting\Certificates\Models\Certificate;
use RoundlyConsulting\Testing\Fixtures\Concerns\CountsCreations;

/**
 * A host's own registry model, exactly as `certificates.model` invites: a
 * subclass of the packaged model. It exists to prove the config key is honoured
 * everywhere the package reads the registry — not just on the relation.
 *
 * `CountsCreations` is REQUIRED by `toHonourModelSwap`, not detected. Without it the
 * expectation's created-event half drops in silence — 15 assertions quietly become 13 —
 * so a caller who never thought about the trait gets a weaker proof under the same name.
 * It is what proves a row was created AS this class rather than merely hydrated into it:
 * re-querying through the host class re-hydrates the row whatever it was created as, so
 * an assertion on the returned object alone can stay green over a real seam bypass.
 */
final class CustomCertificate extends Certificate
{
    use CountsCreations;

    public function isHostModel(): bool
    {
        return true;
    }
}
