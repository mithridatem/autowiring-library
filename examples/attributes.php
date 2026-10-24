<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Mithridatem\Autowiring\Attribute\Inject;
use Mithridatem\Autowiring\Container;

interface ReportFormatterInterface
{
    public function format(string $name): string;
}

final class ReportFormatter implements ReportFormatterInterface
{
    public function __construct(private string $prefix) {}

    public function format(string $name): string { return $this->prefix . $name; }
}

final class ReportService
{
    public function __construct(#[Inject('formatter.report')] private ReportFormatterInterface $formatter) {}

    public function run(): string { return $this->formatter->format('ventes'); }
}

$container = (new Container())->load(__DIR__ . '/config/services.php');
echo $container->get(ReportService::class)->run() . PHP_EOL;
