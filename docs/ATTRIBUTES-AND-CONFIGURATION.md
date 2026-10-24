# Attribut Inject et configuration centralisée

Ces fonctionnalités sont disponibles depuis **v1.1.0**, publiée sur Packagist.
Elles restent compatibles avec 1.2.0, qui ajoute la découverte. Pour installer
la version 1.2 :

```bash
composer require mithridatem/autowiring:^1.2
```

L'injection classique par type continue à fonctionner sans attribut. Les classes
concrètes n'ont pas besoin d'être enregistrées et les interfaces peuvent toujours
être associées avec `bind()`.

## Choisir un service avec #[Inject]

L'attribut indique l'identifiant du service à injecter dans un paramètre de
constructeur. Exemple dans une application possédant une interface de logger :

```php
<?php

namespace App\Service;

use App\Logging\LoggerInterface;
use Mithridatem\Autowiring\Attribute\Inject;

final class ExportService
{
    public function __construct(
        #[Inject('logger.export')]
        private LoggerInterface $logger
    ) {}
}
```

Enregistrer ensuite `logger.export` avec `set()` ou `factory()` dans la
configuration du conteneur. Cet identifiant peut aussi être un nom de classe :
`#[Inject(FileLogger::class)]` demande alors cette classe directement.

L'objet sélectionné doit respecter le type du paramètre. Un identifiant absent
ou un objet incompatible provoque une exception ; le conteneur ne revient pas
silencieusement à l'association par défaut de l'interface.

## Déplacer la configuration dans config/services.php

Le fichier retourne une **Closure recevant le conteneur**. Il ne retourne pas un
tableau de services. Exemple à adapter aux classes de l'application :

```php
<?php

declare(strict_types=1);

use App\Database\Mysql;
use App\Logging\FileLogger;
use Mithridatem\Autowiring\Container;
use Psr\Container\ContainerInterface;

return static function (Container $container): void {
    // FileLogger est une classe applicative implémentant LoggerInterface,
    // dont le constructeur demande un paramètre string $path.
    $container->configure(FileLogger::class, [
        'path' => dirname(__DIR__) . '/var/log/export.log',
    ]);

    $container->factory(
        'logger.export',
        static fn (ContainerInterface $c) => $c->get(FileLogger::class)
    );

    // Mysql est la classe de connexion de l'application et retourne un PDO.
    $container->factory(PDO::class, static fn (): PDO => Mysql::connectBdd());
};
```

Adapter les namespaces, le fichier de configuration SQL et le chemin de log au
projet ; ces classes applicatives ne sont pas fournies par la librairie.
La fabrique permet de différer la connexion SQL au premier besoin.

## Un index.php réduit

Avec le routeur et des contrôleurs applicatifs autoloadables sous `App\Controller` :

```php
<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Autowiring\Container;
use Mithridatem\Routing\Loader\AttributeRouteLoader;
use Mithridatem\Routing\Router;

// Charger d'abord la configuration habituelle de l'application si nécessaire.
$container = (new Container())->load(dirname(__DIR__) . '/config/services.php');
$router = new Router(controllerResolver: new ContainerControllerResolver($container));

(new AttributeRouteLoader())->loadDirectory(
    router: $router,
    directory: dirname(__DIR__) . '/src/Controller',
    namespace: 'App\\Controller'
);

echo $router->dispatch();
```

Les autres réglages du routeur (droits, basePath) restent configurables avant le
dispatch. L'attribut `Inject` concerne les constructeurs ; il n'injecte pas de
services dans les paramètres des actions du routeur.

## Ordre de priorité

Pour chaque paramètre de constructeur non variadique et non passé par référence :

1. Une valeur fournie par `configure()` est utilisée en priorité, même `null`
   lorsque le type l'accepte.
2. Sinon, `#[Inject('identifiant')]` sélectionne le service précis.
3. Sinon, le type déclaré est résolu comme auparavant.
4. Sinon, une valeur par défaut est utilisée si elle existe.
5. À défaut, une exception indique le paramètre non résolu.

Le deuxième cas n'utilise pas de repli en cas d'erreur. Pour remplacer un service
annoté dans un test, enregistrer un autre objet sous son identifiant ou fournir
directement le paramètre avec `configure()` avant toute résolution.

## Imports et gestion des erreurs

Un fichier peut charger un autre fichier de configuration :

```php
return static function (\Mithridatem\Autowiring\Container $container): void {
    $container->load(__DIR__ . '/logging.php');
    $container->load(__DIR__ . '/repositories.php');
};
```

- Les chemins sont explicites ; il n'y a pas de découverte automatique des
  services ou des fichiers. Utiliser `__DIR__` pour éviter de dépendre du répertoire courant.
- Les définitions doivent être enregistrées avant le premier `get()`.
- Ne pas appeler `get()` directement pendant le chargement : enregistrer une
  fabrique contenant cet appel, comme dans l'exemple du logger.
- Un fichier introuvable, un retour invalide, une erreur PHP ou un import
  circulaire provoque une `ContainerException`, avec le chemin et la cause.
- En cas d'échec, les définitions du conteneur sont restaurées à leur état
  précédant cet appel à `load()`. Les effets externes du code PHP (fichiers,
  modifications d'objets existants, etc.) ne sont pas annulés.
- Les fichiers sont exécutés avec `require` : charger uniquement des fichiers
  PHP de configuration maîtrisés par l'application, jamais un chemin reçu du client HTTP.

## Périmètre de l'attribut

`Inject` accepte un identifiant non vide et un paramètre typé avec une classe ou
une interface, y compris sur une propriété promue du constructeur. Les
constructeurs hérités sont pris en charge. Un seul attribut `Inject` est autorisé
par paramètre. L'injection de propriétés ou de méthodes n'est pas ajoutée.

Les scalaires restent fournis par `configure()` ou une fabrique : ne pas utiliser
`#[Inject]` pour un mot de passe, un chemin ou un entier. Les types union et
intersection, les références et les variadiques restent hors du périmètre de
l'attribut. Les services conservent leur cycle de vie partagé dans le conteneur.

## Exemple fourni et dépannage

Depuis un clone du dépôt (v1.1.0 ou supérieure) :

```bash
composer install
composer example-attributes
composer test
```

L'exemple `examples/attributes.php` et sa configuration
`examples/config/services.php` affichent `Rapport : ventes`, sans base de données.

| Message | Action |
| --- | --- |
| `Inject requires a named object type` | Utiliser un type classe/interface ; configurer les scalaires avec `configure()`. |
| `Service not found: logger.export` | Enregistrer cet identifiant avant la résolution. |
| `must implement or extend` | Vérifier que l'objet sélectionné respecte le type du paramètre. |
| `Configuration must return a Closure` | Retourner `static function (Container $container): void { ... }`. |
| `Do not resolve services while loading configuration` | Déplacer l'appel à `get()` dans une fabrique. |
| `Circular configuration import` | Retirer l'import qui recharge un fichier déjà en cours de chargement. |

Depuis 1.2.0, la [découverte optionnelle](DISCOVERY.md) peut compléter ces fichiers
de configuration et associer automatiquement les interfaces à une implémentation unique.

Voir également le [guide général](USAGE.md) et le [README](../README.md).
