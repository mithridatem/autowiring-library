# Démarrage rapide : intégrer autowiring et routing dans un projet

Objectif : configurer les dépendances dans **`config/service.php`** et démarrer
l'application dans **`public/index.php`**. Ce guide utilise la version 1.2 ou
supérieure, qui fournit la découverte des implémentations.

L'exemple s'intègre à un projet existant : les contrôleurs, repositories et la
classe de connexion MySQL appartiennent à votre application.

## 1. Installer les deux librairies

Depuis la racine du projet :

```bash
composer require mithridatem/autowiring:^1.2 mithridatem/router:^1.2
```

Le projet doit utiliser PHP 8.1 ou supérieur. Pour MySQL, activer `pdo_mysql`.
Si le tag 1.2.0 n'est pas encore publié, attendre son indexation sur Packagist
avant d'exécuter cette commande.

Cet exemple suppose les correspondances suivantes :

| Élément | Chemin ou namespace attendu |
| --- | --- |
| Autoload du projet | `App\\` vers `src/` |
| Contrôleurs avec `#[Route]` | `src/Controller/` → `App\Controller` |
| Repositories | `src/Repository/` → `App\Repository` |
| Classe de connexion existante | `App\Database\Mysql` |

Si nécessaire, compléter la section `autoload` du `composer.json` de l'application
en conservant ses autres réglages :

```json
{
    "autoload": {
        "psr-4": {
            "App\\": "src/"
        }
    }
}
```

Puis lancer `composer dump-autoload`. Adapter les chemins et namespaces ci-dessous
s'ils sont différents dans votre projet.

## 2. Créer config/service.php

Créer le dossier `config` à la racine du projet, puis ce fichier :

```php
<?php

declare(strict_types=1);

use App\Database\Mysql;
use Mithridatem\Autowiring\Container;

return static function (Container $container): void {
    // Connexion créée au premier besoin, puis partagée.
    $container->factory(
        PDO::class,
        static fn (): PDO => Mysql::connectBdd()
    );

    // Découvrir les implémentations des interfaces de repositories.
    $container->discover(
        dirname(__DIR__) . '/src/Repository',
        'App\\Repository'
    );
};
```

`Mysql` est votre classe existante, pas une classe fournie par la librairie.
Adapter son import ; `Mysql::connectBdd()` doit retourner un objet `PDO`.

Le nom **`service.php` au singulier** est volontaire : utiliser exactement ce même
nom lors du chargement dans `index.php`. Le nom du fichier est libre ; c'est le
chemin fourni à `load()` qui compte.

## 3. Créer ou adapter public/index.php

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Mithridatem\Autowiring\Container;
use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Routing\Loader\AttributeRouteLoader;
use Mithridatem\Routing\Router;

// Charger ici votre configuration habituelle : .env, constantes SQL, etc.

// Charger les dépendances de l'application.
$container = (new Container())->load(
    dirname(__DIR__) . '/config/service.php'
);

// Le routeur délègue la construction des contrôleurs au conteneur.
$router = new Router();
$router->setControllerResolver(
    new ContainerControllerResolver($container)
);

// Conserver ici les éventuels réglages de basePath et d'autorisations.

// Charger les routes déclarées dans les contrôleurs avec #[Route].
(new AttributeRouteLoader())->loadDirectory(
    router: $router,
    directory: dirname(__DIR__) . '/src/Controller',
    namespace: 'App\\Controller'
);

echo $router->dispatch();
```

Il n'est pas nécessaire d'appeler `get()` pour chaque contrôleur ou service :
le resolver demande le contrôleur au conteneur au moment du dispatch.

## 4. Vérifier le constructeur du repository

Pour recevoir la connexion, le constructeur de votre repository abstrait doit
demander un objet `PDO`. Dans `src/Repository/AbstractRepository.php` :

```php
<?php

namespace App\Repository;

use PDO;

abstract class AbstractRepository
{
    public function __construct(protected PDO $connect) {}
}
```

Les repositories concrets héritent de ce constructeur. Si un enfant définit
son propre constructeur, il doit recevoir `PDO $connect` et appeler
`parent::__construct($connect)`.

Les services et contrôleurs déclarent eux aussi leurs dépendances par des
paramètres typés dans leurs constructeurs. La chaîne devient :
**contrôleur → service → repository → PDO**. Toutes les couches ne sont pas
obligatoires ; un contrôleur peut également demander directement un repository.

## 5. Vérifier une route existante

Ouvrir une URL déjà déclarée avec `#[Route]` dans votre application. Une route
dont le contrôleur demande un repository permettra de vérifier aussi la connexion.
Conserver la configuration habituelle du serveur web vers `public/index.php`.

Trois règles à retenir :

- **Classe concrète** : résolution automatique par le type du constructeur.
- **Interface avec une seule implémentation découverte** : association automatique.
- **Plusieurs implémentations** : ajouter le `bind()` choisi dans `config/service.php`
  ou utiliser `#[Inject]` sur le paramètre concerné.

Toutes les définitions doivent être configurées avant le premier `get()` ou le
dispatch. La connexion PDO reste gérée par sa fabrique ; il n'est pas nécessaire
de découvrir la classe `Mysql`.

Pour aller plus loin : [guide général et dépannage](USAGE.md),
[choix d'un service et configuration](ATTRIBUTES-AND-CONFIGURATION.md),
[découverte des implémentations](DISCOVERY.md).
