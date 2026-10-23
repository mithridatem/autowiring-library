<?php

declare(strict_types=1);

namespace Example\Repository;

final class InMemoryBookRepository implements BookRepositoryInterface
{
    public function findTitle(string $id): ?string
    {
        return ['1' => 'Le Petit Prince', '2' => 'Vingt mille lieues sous les mers'][$id] ?? null;
    }
}
