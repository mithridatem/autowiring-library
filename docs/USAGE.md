# Guide d'utilisation de Mithridatem Autowiring

Ce guide accompagne l'intégration de la version 1.x dans une application PHP.
Il suppose PHP 8.1 ou supérieur, Composer et un point d'entrée `public/index.php`.

Pour la prochaine version mineure, le [guide attributs et configuration](ATTRIBUTES-AND-CONFIGURATION.md)
présente `#[Inject]` et le chargement de `config/services.php`. Ces ajouts sont
optionnels et ne sont pas encore disponibles dans le tag 1.0.0 ; les exemples
ci-dessous restent utilisables avec la version 1.0.0.

## 1. Installer et charger la librairie

Depuis la racine de l'application :

```bash
composer require mithridatem/autowiring:^1.0
```

Configurer l'autoload de **l'application**, en conservant ses autres réglages
Composer :

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

Puis régénérer l'autoload :

```bash
composer dump-autoload
```

Dans `public/index.php`, charger `dirname(__DIR__) . '/vendor/autoload.php'`.
Chaque classe applicative doit correspondre à son namespace et à son fichier PSR-4.

## 2. Déclarer les dépendances dans les constructeurs

Par exemple, dans `src/Service/BookService.php` :

```php
<?php

namespace App\Service;

use App\Repository\BookRepository;

final class BookService
{
    public function __construct(private BookRepository $books) {}

    public function listBooks(): array
    {
        return $this->books->findAll();
    }
}
```

Le conteneur peut construire cette classe avec
`$container->get(BookService::class)`. Il construit aussi son repository.
Il n'est pas nécessaire d'appeler `get()` séparément pour chaque dépendance.

Si un constructeur demande une interface, préciser son implémentation :

```php
$container->bind(BookRepositoryInterface::class, BookRepository::class);
```

Dans ce cas, `BookRepository` doit implémenter cette interface, et les classes
doivent être importées avec leurs namespaces. Le conteneur n'invente pas les
interfaces : utiliser cette association uniquement si elles existent dans le projet.

## 3. Injecter PDO dans un repository abstrait

L'extension `pdo_mysql` et une connexion MySQL configurée sont nécessaires pour
cet exemple. La configuration SQL appartient à l'application, pas à la librairie.

Dans `src/Repository/AbstractRepository.php` :

```php
<?php

namespace App\Repository;

use PDO;

abstract class AbstractRepository
{
    public function __construct(protected PDO $connect) {}
}
```

Dans `src/Repository/BookRepository.php` :

```php
<?php

namespace App\Repository;

use PDO;

final class BookRepository extends AbstractRepository
{
    public function findAll(): array
    {
        return $this->connect
            ->query('SELECT * FROM book')
            ->fetchAll(PDO::FETCH_ASSOC);
    }
}
```

Adapter la requête à la table de l'application. Le conteneur inspecte le
constructeur hérité et lui fournit `PDO`. Si le repository définit son propre
constructeur, il doit recevoir `PDO $connect` et appeler
`parent::__construct($connect)`.

Enregistrer la connexion avec l'une des deux approches suivantes :

```php
// Connexion créée immédiatement par l'application.
$container->set(PDO::class, Mysql::connectBdd());
```

```php
// Connexion créée au premier besoin, puis partagée entre les repositories.
$container->factory(PDO::class, fn (): PDO => Mysql::connectBdd());
```

Choisir une seule approche. `Mysql` est ici une classe **de l'application**,
et non une classe fournie par la librairie. Sa méthode `connectBdd()` doit retourner
un objet PDO. Adapter son import au namespace utilisé et charger sa configuration
avant le premier besoin de connexion.

## 4. Brancher le routeur dans index.php

Installer le routeur dans l'application :

```bash
composer require mithridatem/router:^1.2
```

Exemple de `src/Controller/BookController.php` :

```php
<?php

namespace App\Controller;

use App\Service\BookService;
use Mithridatem\Routing\Attribute\Route;

final class BookController
{
    public function __construct(private BookService $books) {}

    #[Route(path: '/books', method: 'GET')]
    public function index(): string
    {
        return json_encode($this->books->listBooks(), JSON_THROW_ON_ERROR);
    }
}
```

Dans `public/index.php`, en supposant que la classe de connexion s'appelle
`App\Database\Mysql` et que sa configuration a été chargée :

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use App\Database\Mysql;
use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Autowiring\Container;
use Mithridatem\Routing\Loader\AttributeRouteLoader;
use Mithridatem\Routing\Router;

// Charger ici la configuration de l'application si nécessaire.

$container = new Container();
$container->factory(PDO::class, fn (): PDO => Mysql::connectBdd());

// Ajouter ici les éventuelles associations d'interfaces ou valeurs configurées.

$router = new Router();
$router->setControllerResolver(new ContainerControllerResolver($container));

// Conserver ici les éventuels réglages de basePath et d'autorisations du projet.

(new AttributeRouteLoader())->loadDirectory(
    router: $router,
    directory: dirname(__DIR__) . '/src/Controller',
    namespace: 'App\\Controller'
);

header('Content-Type: application/json; charset=utf-8');
echo $router->dispatch();
```

La chaîne obtenue est : contrôleur → service → repository → PDO. Il n'est pas
nécessaire d'associer la classe abstraite : le service demande le repository
concret. Les paramètres d'URL restent gérés par le routeur ; le conteneur intervient
dans les constructeurs, pas dans les paramètres des actions.

## 5. Fournir un paramètre comme $path

Pour une classe applicative `FileLogger` dont le constructeur demande
`string $path`, configurer la valeur sur cette classe concrète :

```php
$container->configure(FileLogger::class, [
    'path' => dirname(__DIR__) . '/var/log/application.log',
]);
```

La clé doit correspondre exactement au nom du paramètre. Une valeur par défaut
du constructeur est aussi utilisable lorsqu'aucune valeur n'est configurée.
Pour une création plus complexe, utiliser `factory()` ; sa fonction peut recevoir
un `Psr\Container\ContainerInterface` pour demander d'autres services.

## 6. Comprendre le cycle de vie

Configurer tous les `bind()`, `configure()`, `set()` et `factory()` avant le
premier `get()` ou le premier dispatch qui demande un contrôleur. Après ce premier
`get()`, la configuration est figée, même en cas d'échec de résolution.

Les objets sont partagés dans un même conteneur. Une fabrique est appelée une fois
après une création réussie ; les demandes suivantes récupèrent l'objet mémorisé.
Ce partage est local au conteneur, pas global à toute l'application.

## 7. Résoudre les erreurs courantes

| Message ou symptôme | Vérification |
| --- | --- |
| `Service not found` | Classe autoloadable ? Interface associée ? Namespace correct ? |
| `Unresolvable parameter` | Paramètre scalaire configuré ? Interface associée ? Type de constructeur déclaré ? |
| `Unknown constructor parameter` | La clé passée à `configure()` correspond-elle au nom exact du paramètre ? |
| `Configure the container before the first get()` | Déplacer la configuration avant toute résolution et recréer le conteneur pour réessayer. |
| `Circular dependency` | Lire la chaîne indiquée et supprimer la dépendance réciproque entre services. |
| Message sur une classe interne | Fournir PDO ou cette classe avec `set()` ou `factory()`. |
| `Cannot resolve controller` | Lire `getPrevious()` pour retrouver la cause d'erreur du conteneur. |
| Propriété `$connect` non initialisée | Vérifier l'appel à `parent::__construct($connect)` dans le constructeur du repository enfant. |

Pour vérifier l'installation : `composer show mithridatem/autowiring`.
Pour les versions et la publication : [PUBLISHING.md](PUBLISHING.md).
Pour l'API et ses limites : [README](../README.md).
