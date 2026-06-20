<?php

declare(strict_types=1);

use RoundlyConsulting\Certificates\Exceptions\AcmeException;
use RoundlyConsulting\Certificates\Exceptions\CertificateException;

it('builds descriptive ACME exceptions', function (callable $factory, string $needle): void {
    $exception = $factory();

    expect($exception)->toBeInstanceOf(CertificateException::class)
        ->and($exception->getMessage())->toContain($needle);
})->with([
    'directory' => [fn (): AcmeException => AcmeException::directoryUnavailable('https://acme.test'), 'directory'],
    'nonce' => [fn (): AcmeException => AcmeException::nonceUnavailable(), 'nonce'],
    'account' => [fn (): AcmeException => AcmeException::accountFailed('boom'), 'boom'],
    'order' => [fn (): AcmeException => AcmeException::orderFailed('boom'), 'boom'],
    'challenge' => [fn (): AcmeException => AcmeException::challengeFailed('app.com', 'bad'), 'app.com'],
    'challenge-no-detail' => [fn (): AcmeException => AcmeException::challengeFailed('app.com'), 'app.com'],
    'finalize' => [fn (): AcmeException => AcmeException::finalizeFailed('boom'), 'boom'],
    'download' => [fn (): AcmeException => AcmeException::downloadFailed('boom'), 'boom'],
    'signing' => [fn (): AcmeException => AcmeException::signingFailed(), 'sign'],
    'keygen' => [fn (): AcmeException => AcmeException::keyGenerationFailed(), 'key'],
    'unexpected-key' => [fn (): AcmeException => AcmeException::unexpectedKey(), 'unsupported'],
]);
