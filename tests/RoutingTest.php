<?php

declare(strict_types=1);

namespace Mithridatem\Autowiring\Tests;

use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Autowiring\Container;
use Mithridatem\Routing\Attribute\Route;
use Mithridatem\Routing\Context\NativeRequestContext;
use Mithridatem\Routing\Exception\RouterException;
use Mithridatem\Routing\Handler\ControllerReference;
use Mithridatem\Routing\Loader\AttributeRouteLoader;
use Mithridatem\Routing\Router;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;

final class RoutingTest extends TestCase
{
    public function testAttributeRoutesInjectConstructorServicesAndKeepUrlParameters(): void
    {
        $container = new Container();
        $router = new Router();
        $router->setControllerResolver(new ContainerControllerResolver($container));
        (new AttributeRouteLoader())->loadController($router, RoutingController::class);

        self::assertSame('Book #42', $router->dispatch(new NativeRequestContext('/books/42', 'GET')));
    }

    public function testLegacyMapControllerStillWorks(): void
    {
        $router = new Router(controllerResolver: new ContainerControllerResolver(new Container()));
        $router->mapController('GET', '/legacy/{id}', RoutingController::class, 'show');
        self::assertSame('Book #7', $router->dispatch(new NativeRequestContext('/legacy/7', 'GET')));
    }

    public function testPrivateActionIsRejected(): void
    {
        $this->expectException(RouterException::class);
        (new ContainerControllerResolver(new Container()))->resolve(new ControllerReference(RoutingController::class, 'hidden'));
    }

    public function testMissingActionIsRejected(): void
    {
        $this->expectException(RouterException::class);
        (new ContainerControllerResolver(new Container()))->resolve(new ControllerReference(RoutingController::class, 'missing'));
    }

    public function testContainerFailureIsWrappedAndPreserved(): void
    {
        try {
            (new ContainerControllerResolver(new Container()))->resolve(new ControllerReference('UnknownController', 'index'));
            self::fail('Expected exception');
        } catch (RouterException $error) {
            self::assertNotNull($error->getPrevious());
            self::assertStringContainsString('UnknownController', $error->getMessage());
        }
    }

    public function testAnyPsrContainerCanBeUsedButMustReturnTheExpectedController(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $instance = new RoutingController(new RoutingService());
        $container->expects(self::once())->method('get')->with(RoutingController::class)->willReturn($instance);
        $action = (new ContainerControllerResolver($container))->resolve(new ControllerReference(RoutingController::class, 'show'));
        self::assertSame('Book #5', $action('5'));
    }

    public function testWrongControllerTypeFromExternalContainerIsRejected(): void
    {
        $container = $this->createMock(ContainerInterface::class);
        $container->method('get')->willReturn(new RoutingService());
        $this->expectException(RouterException::class);
        (new ContainerControllerResolver($container))->resolve(new ControllerReference(RoutingController::class, 'show'));
    }

    public function testCompleteExampleWithDirectoryDiscovery(): void
    {
        $router = require dirname(__DIR__) . '/examples/routing/bootstrap.php';
        self::assertSame('Le Petit Prince', $router->dispatch(new NativeRequestContext('/books/1', 'GET')));
        self::assertSame('Livre introuvable', $router->dispatch(new NativeRequestContext('/books/999', 'GET')));
    }
}

final class RoutingService
{
    public function title(string $id): string { return 'Book #' . $id; }
}

final class RoutingController
{
    public function __construct(private RoutingService $service) {}

    #[Route(path: '/books/{id}', method: 'GET')]
    public function show(string $id): string { return $this->service->title($id); }

    private function hidden(): string { return 'hidden'; }
}
