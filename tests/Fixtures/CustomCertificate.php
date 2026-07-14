<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use RoundlyConsulting\Certificates\Models\Certificate;

/**
 * A host's own registry model, exactly as `certificates.model` invites: a
 * subclass of the packaged model. It exists to prove the config key is honoured
 * everywhere the package reads the registry — not just on the relation.
 */
final class CustomCertificate extends Certificate
{
    public function isHostModel(): bool
    {
        return true;
    }
}
