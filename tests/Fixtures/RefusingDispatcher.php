<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\Tests\Fixtures;

use Illuminate\Bus\Dispatcher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use RoundlyConsulting\Certificates\Jobs\RenewCertificateJob;
use RuntimeException;

/**
 * The real bus, except that pushing a renewal for one of the given certificate ids fails —
 * the queue backend dropping one dispatch mid-run.
 */
final class RefusingDispatcher extends Dispatcher
{
    /**
     * @param  list<int>  $refuse
     */
    public function __construct(private readonly array $refuse)
    {
        parent::__construct(app(), fn (?string $connection = null) => app(QueueFactory::class)->connection($connection));
    }

    public function dispatch($command)
    {
        if ($command instanceof RenewCertificateJob && in_array($command->certificateId, $this->refuse, true)) {
            throw new RuntimeException('Queue connection refused.');
        }

        return parent::dispatch($command);
    }
}
