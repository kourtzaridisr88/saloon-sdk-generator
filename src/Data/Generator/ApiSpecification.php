<?php

namespace Crescat\SaloonSdkGenerator\Data\Generator;

class ApiSpecification
{
    /**
     * @param  SecurityRequirement[]  $securityRequirements
     * @param  Endpoint[]  $endpoints
     */
    public function __construct(
        public ?string $name,
        public ?string $description,
        public ?BaseUrl $baseUrl,
        public array $securityRequirements = [],
        public ?Components $components = null,
        public array $endpoints = [],
    ) {}
}
