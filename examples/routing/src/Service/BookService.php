<?php

declare(strict_types=1);

namespace Example\Service;

use Example\Repository\BookRepositoryInterface;

final class BookService
{
    public function __construct(private BookRepositoryInterface $books) {}

    public function title(string $id): string
    {
        return $this->books->findTitle($id) ?? 'Livre introuvable';
    }
}
