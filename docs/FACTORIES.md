# Factories simples et avancées

La librairie prend en charge deux formes de factories, enregistrées avec la même API
`Container::factory(string $id, callable|FactoryInterface $factory)`.

## Factory simple : closure ou callable (compatible avec les versions précédentes)

```php
use Psr\Container\ContainerInterface;

$container->factory(
    PDO::class,
    static fn (ContainerInterface $c): PDO => new PDO('sqlite::memory:')
);
```

Cette forme reste la plus simple. Les callables existants continuent à fonctionner.

## Factory avancée : classe réutilisable

`FactoryInterface` définit `create(ContainerInterface $container): object`.
Le conteneur valide automatiquement que l'objet retourné correspond à l'interface
ou à la classe enregistrée sous l'identifiant.

```php
<?php

namespace App\Factory;

use Mithridatem\Autowiring\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class HttpClientFactory implements FactoryInterface
{
    public function create(ContainerInterface $container): HttpClientInterface
    {
        return HttpClient::create([
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }
}
```

Installer Symfony HttpClient **uniquement dans l'application** qui en a besoin :

```bash
composer require symfony/http-client
```

Dans `config/service.php` :

```php
<?php

use App\Factory\HttpClientFactory;
use Mithridatem\Autowiring\Container;
use Symfony\Contracts\HttpClient\HttpClientInterface;

return static function (Container $container): void {
    $container->factory(HttpClientInterface::class, new HttpClientFactory());
};
```

Dans `public/index.php`, charger les services avant la première résolution :

```php
$container = (new Container())->load(dirname(__DIR__) . '/config/service.php');
```

Puis injecter l'interface dans une classe métier :

```php
<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

final class ExternalUserService
{
    public function __construct(private HttpClientInterface $httpClient) {}

    public function getUsers(): array
    {
        return $this->httpClient
            ->request('GET', 'https://jsonplaceholder.typicode.com/users')
            ->toArray();
    }
}
```

```php
$service = $container->get(\App\Service\ExternalUserService::class);
```

Ce dernier exemple déclenche une vraie requête HTTP. Pour les tests, préférer
`MockHttpClient` et enregistrer l'instance avec `set()`.

## Comportement et compatibilité

- Une factory est appelée uniquement lors du premier `get()` de son service.
- Le résultat est partagé pendant la durée de vie du conteneur.
- `has()` ne déclenche pas la factory.
- `bind()`, `set()` et `factory()` conservent leur priorité de remplacement pour un même identifiant.
- `load()` restaure les définitions précédentes si la configuration échoue.
- Les cycles sont détectés et les exceptions de construction conservent leur cause.
- Tout `callable` reste valide ; `FactoryInterface` est facultative.
- Une factory classe n'est pas découverte automatiquement : elle doit être enregistrée explicitement.
- Ne pas appeler `get()` dans la configuration : laisser la factory résoudre ses dépendances lors de son exécution.

Le package `mithridatem/autowiring` reste indépendant de Symfony HttpClient :
la classe `HttpClientFactory` présentée ici appartient à l'application.
