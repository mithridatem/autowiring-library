<?php

declare(strict_types=1);

namespace Example\Repository;

interface BookRepositoryInterface
{
    public function findTitle(string $id): ?string;
}
