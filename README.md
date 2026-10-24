# Mithridatem Autowiring

Librairie PHP d'injection de dépendances par constructeur, compatible PSR-11.
Elle construit automatiquement les classes concrètes et leurs dépendances,
avec un adaptateur optionnel pour [Mithridatem Router](https://github.com/evaluationWeb/router-librairie).

Prérequis : PHP 8.1 ou supérieur et Composer.

Pour une intégration guidée (index.php, routing, repositories et PDO), consulter
le [guide d'utilisation](https://github.com/mithridatem/autowiring-library/blob/main/docs/USAGE.md).

La prochaine version mineure ajoute `#[Inject]` sur les paramètres de constructeur
et `Container::load()` pour centraliser les définitions dans `config/services.php`.
Ces ajouts ne sont pas inclus dans v1.0.0. Consulter le
[guide attributs et configuration](https://github.com/mithridatem/autowiring-library/blob/main/docs/ATTRIBUTES-AND-CONFIGURATION.md)
pour les exemples, l'ordre de priorité et le dépannage.

## Installation

```bash
composer require mithridatem/autowiring:^1.0
```

Le cœur requiert seulement `psr/container`. Le routeur et PHPUnit sont des
dépendances de développement de ce dépôt ; ils ne sont pas imposés aux applications.

## Résolution automatique

```php
require __DIR__ . '/vendor/autoload.php';

use Mithridatem\Autowiring\Container;

final class BookRepository {}

final class BookService
{
    public function __construct(public BookRepository $books) {}
}

$container = new Container();
$service = $container->get(BookService::class);
```

Le conteneur lit le constructeur avec la réflexion PHP, résout `BookRepository`,
puis crée `BookService`. Aucun `bind()` n'est nécessaire pour une classe concrète.
Les classes doivent être chargées ou accessibles via l'autoload de l'application.

## Associer une interface

```php
$container->bind(BookRepositoryInterface::class, SqlBookRepository::class);
```

L'implémentation doit être une classe instanciable respectant le contrat demandé.
Une classe parente peut également être associée à une sous-classe.

## Fournir des paramètres comme `$path`

```php
final class FileLogger
{
    public function __construct(public string $path) {}
}

$container = (new Container())->configure(FileLogger::class, [
    'path' => __DIR__ . '/var/log/application.log',
]);

$logger = $container->get(FileLogger::class);
```

Les clés correspondent aux noms exacts des paramètres du constructeur. Une faute
de frappe provoque une exception. La configuration s'applique à la classe concrète,
y compris lorsqu'elle est obtenue par un `bind()` d'interface. Une valeur configurée
peut aussi être un objet ou `null` si le type du paramètre l'accepte.

## Enregistrer un objet ou une fabrique

```php
use Psr\Container\ContainerInterface;

$container = new Container();

$container->set(PDO::class, $pdo); // Connexion déjà créée par l'application.

$container->factory(BookRepositoryInterface::class,
    fn (ContainerInterface $c) => new SqlBookRepository($c->get(PDO::class))
);
```

Une fabrique est appelée lors du premier besoin et reçoit le conteneur. Elle
retourne un objet. Pour un identifiant de classe ou d'interface, le résultat doit
respecter ce type. On peut aussi utiliser un identifiant libre comme `logger.file`.
Les classes internes PHP, comme PDO, doivent être fournies par `set()` ou `factory()`.

## Cycle de vie et règles de résolution

- Effectuer tous les `bind()`, `set()`, `factory()` et `configure()` **avant le premier
  `get()`**. Ce premier appel fige la configuration, même s'il échoue. Les fabriques
  utilisent `get()` pour leurs dépendances, sans modifier les définitions.
- Les objets sont créés à la demande et partagés pendant la durée de vie du
  conteneur. Une interface associée et sa classe concrète retournent le même objet.
  Deux définitions de fabrique distinctes restent deux services distincts.
- Une valeur explicitement configurée est prioritaire. Ensuite, un paramètre de
  constructeur peut sélectionner un service avec `#[Inject]` dans la prochaine
  version mineure (identifiant objet et type vérifiés). Sans attribut, le comportement
  reste inchangé : un paramètre de
  type objet résoluble est injecté. À défaut, la valeur par défaut du paramètre
  est utilisée si elle existe. Sinon une exception explique le paramètre manquant.
- Un paramètre nullable sans valeur par défaut reste obligatoire. Un objet
  résoluble qui échoue pendant sa construction ne devient pas silencieusement `null`.
- `has()` ne construit rien et ne vérifie pas tout le graphe : `true` signifie
  qu'une entrée ou une classe instanciable existe, pas que sa construction réussira.
- Les types union et intersection nécessitent une valeur par défaut, une
  configuration explicite ou une fabrique. Les paramètres variadiques et les
  références nécessitent une fabrique. Les types `self` et `parent` sont résolus
  dans la classe déclarant le constructeur.
- L'injection de propriétés, l'injection dans les méthodes, les services non
  partagés et la compilation ne font pas partie de cette première version.

`bind()`, `set()` et `factory()` remplacent une définition précédente pour le même
identifiant avant le premier `get()`. Plusieurs `configure()` fusionnent les paramètres.
Le partage d'instances n'est pas un singleton global. Dans un serveur persistant,
créer un conteneur par requête si des services conservent un état propre à la requête.

## Intégration avec le routeur

Installer les deux paquets dans l'application :

```bash
composer require mithridatem/autowiring:^1.0 mithridatem/router:^1.2
```

Aucun changement de la
librairie de routing n'est nécessaire : son `ControllerResolverInterface` est le
point d'intégration.

```php
use Mithridatem\Autowiring\Bridge\Routing\ContainerControllerResolver;
use Mithridatem\Autowiring\Container;
use Mithridatem\Routing\Loader\AttributeRouteLoader;
use Mithridatem\Routing\Router;

$container = (new Container())->bind(
    BookRepositoryInterface::class,
    InMemoryBookRepository::class
);

$router = new Router();
$router->setControllerResolver(new ContainerControllerResolver($container));

(new AttributeRouteLoader())->loadDirectory(
    router: $router,
    directory: __DIR__ . '/../src/Controller',
    namespace: 'App\\Controller'
);

echo $router->dispatch();
```

Le resolver accepte tout `Psr\Container\ContainerInterface`, et pas seulement ce
conteneur. Il retourne un callable au routeur et conserve la cause d'une erreur
de construction dans une `RouterException`.

Les dépendances de constructeur sont fournies par le conteneur. Les paramètres
d'URL, comme `$id` dans `show(string $id)`, sont fournis par le routeur. L'adaptateur
n'injecte ni services ni objets de requête dans les paramètres des actions.
Les déclarations `mapController()` continuent également à fonctionner.

### Exemple complet à exécuter

L'exemple `examples/routing/` contient un contrôleur avec `#[Route]`, un service,
une interface de repository et un repository en mémoire, sans base de données.
Depuis la racine du dépôt après `composer install` :

```bash
php -S 127.0.0.1:8080 -t examples/routing/public examples/routing/public/index.php
```

Ouvrir `http://127.0.0.1:8080/books/1` : la réponse est `Le Petit Prince`.
Les classes de l'exemple sont chargées par l'autoload de développement du dépôt.
Dans une application consommatrice, utiliser ses propres classes et son propre
autoload PSR-4 : les classes `Example\` ne sont pas exportées par le paquet.

## Erreurs et tests

- `NotFoundException` implémente `NotFoundExceptionInterface` pour une entrée
  demandée inexistante ou une interface non associée.
- `ContainerException` implémente `ContainerExceptionInterface` pour les cycles,
  les paramètres manquants et les erreurs de construction. La cause est conservée.
- Une dépendance manquante à l'intérieur d'un service existant est une erreur
  de construction de ce service, conformément au contrat PSR-11.

```bash
composer validate --strict
composer check
```

PHPUnit couvre la résolution, les interfaces, le partage, les paramètres, les
fabriques, les erreurs, la reprise après échec et le dispatch du routeur avec
attributs, découverte de répertoire et paramètres d'URL. La CI exécute ces contrôles
sur PHP 8.1, 8.3 et 8.4.

Les services métier reçoivent leurs dépendances dans leurs constructeurs.
Réserver l'usage du conteneur au démarrage, aux fabriques et au resolver ; les
repositories et services n'ont pas besoin de connaître le conteneur.

## Organisation et distribution

| Emplacement | Contenu | Archive de distribution |
| --- | --- | --- |
| `src/` | Conteneur, exceptions PSR-11, adaptateur optionnel | Inclus |
| `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md` | Métadonnées, licence, usage et historique | Inclus |
| `tests/`, `examples/`, `scripts/`, `phpunit.xml` | Développement, exemples et vérifications | Exclus |
| `.github/`, `docs/` | CI et procédure de publication | Exclus |

L'autoload de production expose uniquement `Mithridatem\Autowiring\` depuis
`src/`. Les tests et les classes `Example\` appartiennent à l'autoload de
développement. Pour exécuter les exemples ou les tests, cloner le dépôt et
installer les dépendances de développement plutôt que travailler dans `vendor/`.

La procédure complète figure dans [docs/PUBLISHING.md](https://github.com/mithridatem/autowiring-library/blob/main/docs/PUBLISHING.md).
Les changements sont consignés dans [CHANGELOG.md](CHANGELOG.md).
