<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Exception;

use Psr\Container\ContainerExceptionInterface;
use RuntimeException;

class ContainerException extends RuntimeException implements ContainerExceptionInterface
{
}
