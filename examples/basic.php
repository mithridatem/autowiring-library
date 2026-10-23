<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Mithridatem\Autowiring\Container;

interface GreetingInterface
{
    public function greet(string $name): string;
}

final class Greeting implements GreetingInterface
{
    public function __construct(private string $prefix) {}

    public function greet(string $name): string { return $this->prefix . ', ' . $name; }
}

final class Welcome
{
    public function __construct(private GreetingInterface $greeting) {}

    public function run(): string { return $this->greeting->greet('Mathieu'); }
}

$container = (new Container())
    ->bind(GreetingInterface::class, Greeting::class)
    ->configure(Greeting::class, ['prefix' => 'Bonjour']);

echo $container->get(Welcome::class)->run() . PHP_EOL;
