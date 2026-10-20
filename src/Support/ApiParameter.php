<?php

namespace Aurora\Support;

use Attribute;
use BackedEnum;

/**
 * A parameter an API endpoint reads, declared on its controller beside the code that reads it,
 * for the API's documentation (OpenApiSpec): a query parameter (?parent=…), or what a path
 * parameter ({location}) is. The endpoints, their scopes and their path parameters come from
 * the routes themselves.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::IS_REPEATABLE)]
class ApiParameter
{
    /**
     * @param  'query'|'path'  $in
     * @param  'string'|'integer'  $type
     * @param  class-string<BackedEnum>|null  $enum  The values it takes, from an enum's cases.
     */
    public function __construct(
        public string $name,
        public string $description,
        public string $in = 'query',
        public string $type = 'string',
        public bool $required = false,
        public ?string $format = null,
        public ?string $enum = null,
        public mixed $example = null,
    ) {}
}
