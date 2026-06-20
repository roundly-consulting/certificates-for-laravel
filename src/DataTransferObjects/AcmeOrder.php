<?php

declare(strict_types=1);

namespace RoundlyConsulting\Certificates\DataTransferObjects;

final readonly class AcmeOrder
{
    /**
     * @param  list<string>  $domains
     * @param  list<string>  $authorizationUrls
     */
    public function __construct(
        public string $orderUrl,
        public string $finalizeUrl,
        public array $domains,
        public array $authorizationUrls,
        public string $status,
        public ?string $certificateUrl = null,
    ) {}
}
