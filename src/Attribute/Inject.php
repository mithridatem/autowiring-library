<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Attribute;

use Attribute;
use InvalidArgumentException;

/** Select an object service for a constructor parameter. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final class Inject
{
    public function __construct(public readonly string $id)
    {
        if ($id === '') {
            throw new InvalidArgumentException('Inject service identifier must not be empty.');
        }
    }
}
