<?php

declare(strict_types=1);

namespace Example\Controller;

use Example\Service\BookService;
use Mithridatem\Routing\Attribute\Route;

final class BookController
{
    public function __construct(private BookService $books) {}

    #[Route(path: '/books/{id}', method: 'GET')]
    public function show(string $id): string
    {
        return $this->books->title($id);
    }
}
