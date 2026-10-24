# Découverte optionnelle des implémentations

Cette fonctionnalité est prévue pour la prochaine version mineure **1.2.0**.
Elle n'est pas disponible dans v1.1.0. Pour la tester avant publication, utiliser
la branche de la PR ou le code de `main` après fusion. La commande d'installation
après publication sera `composer require mithridatem/autowiring:^1.2`.

## À quoi sert la découverte ?

Les classes concrètes sont déjà résolues par le conteneur. La découverte sert
à associer une **interface** à son implémentation sans écrire un `bind()` pour
chaque interface, lorsque ce choix est unique dans le périmètre configuré.

Pour une application avec l'autoload Composer `App\\` vers `src/` :

```php
<?php

use Mithridatem\Autowiring\Container;

require __DIR__ . '/vendor/autoload.php';

$container = new Container();
$container->discover(__DIR__ . '/src/Repository', 'App\\Repository');

// Si une seule classe découverte implémente cette interface :
$repository = $container->get(\App\Repository\BookRepositoryInterface::class);
```

Les classes et interfaces sont celles de l'application. Par exemple,
`src/Repository/BookRepository.php` doit déclarer `App\Repository\BookRepository`
et implémenter `App\Repository\BookRepositoryInterface`.
La découverte ne configure pas PDO : conserver la fabrique de connexion si le
constructeur du repository l'exige.

## Centraliser dans config/services.php

Depuis v1.1.0, `load()` permet de charger la configuration. La nouvelle méthode
`discover()` peut être utilisée dans la Closure retournée par ce fichier :

```php
<?php

use Mithridatem\Autowiring\Container;

return static function (Container $container): void {
    $container->discover(dirname(__DIR__) . '/src/Repository', 'App\\Repository');
    $container->discover(dirname(__DIR__) . '/src/Service', 'App\\Service');
};
```

Dans `public/index.php` :

```php
$container = (new \Mithridatem\Autowiring\Container())
    ->load(dirname(__DIR__) . '/config/services.php');
```

Brancher ensuite `ContainerControllerResolver` sur le routeur comme dans le
[guide d'utilisation](USAGE.md). La découverte des routes et celle des services
sont deux opérations distinctes. L'exemple `examples/routing/config/services.php`
du dépôt utilise cette nouvelle API pour ses repositories en mémoire.

## Définir précisément le périmètre

- Chaque appel indique **un répertoire existant et son namespace PSR-4**.
- Le parcours est récursif et traite les fichiers `.php`, y compris les sous-dossiers.
- Les fichiers et sous-dossiers liés symboliquement ne sont pas suivis.
- Les classes sont chargées par l'autoload de l'application. Les noms non
  autoloadables sont ignorés ; aucun `require` direct des fichiers n'est effectué
  par le scanner. Vérifier `composer dump-autoload` en cas de classe manquante.
- Une classe autoloadée depuis un autre fichier que celui attendu provoque une
  erreur de correspondance PSR-4, afin d'éviter une association incorrecte.
- Seules les classes instanciables deviennent candidates. Les classes abstraites,
  interfaces, traits et enums ne sont pas des implémentations sélectionnables.
- Toutes les interfaces implémentées sont prises en compte, y compris celles
  héritées d'une classe parente ou d'une autre interface.

Configurer de petits périmètres applicatifs (repositories, services). Il n'est
pas nécessaire de parcourir les entités, `vendor/` ou la totalité d'un projet.
Le namespace accepte les identifiants ASCII habituels, séparés par `\`.

## Découverte et création des objets

Le scanner inspecte les classes mais **n'appelle pas leurs constructeurs**.
Les objets sont construits uniquement lorsqu'un `get()` en a besoin, puis partagés
comme auparavant. Une interface découverte et sa classe concrète utilisent la même
instance. Les contraintes des constructeurs ne sont vérifiées qu'à la résolution.

Le chargement via l'autoloader peut néanmoins exécuter le code PHP de niveau fichier.
Les répertoires doivent donc contenir du code applicatif maîtrisé, avec des fichiers
PSR-4 déclarant leurs classes. La découverte n'est pas un bac à sable.

Plusieurs appels fusionnent les candidates ; redécouvrir la même classe ne crée
pas de doublon. Le résultat est mémorisé dans le conteneur, sans cache persistant
entre deux conteneurs. La découverte a lieu à chaque démarrage qui l'appelle.

## Résoudre une ambiguïté

Si `SqlBookRepository` et `InMemoryBookRepository` implémentent la même interface,
le conteneur ne choisit pas arbitrairement. Lors du `get()` de cette interface,
une `ContainerException` liste les candidates et demande un choix explicite.

Cette vérification est différée à la résolution : on peut découvrir les classes,
puis indiquer le choix avant le premier `get()` :

```php
$container->discover(__DIR__ . '/src/Repository', 'App\\Repository');
$container->bind(
    \App\Repository\BookRepositoryInterface::class,
    \App\Repository\SqlBookRepository::class
);
```

`bind()`, `set()` et `factory()` sont toujours prioritaires sur la découverte,
qu'ils soient déclarés avant ou après elle. On peut également sélectionner une
classe pour un constructeur précis avec `#[Inject(SqlBookRepository::class)]`,
sans imposer une association par défaut à toute l'application.

`has()` peut retourner `true` pour une interface ambiguë : l'entrée existe, mais
sa construction échoue tant qu'aucun choix explicite ne la départage. Un paramètre
optionnel ne masque pas cette ambiguïté en retombant sur sa valeur par défaut.

## Erreurs et compatibilité

| Situation | Comportement |
| --- | --- |
| Une implémentation découverte | Résolution automatique de l'interface. |
| Aucune implémentation | Comportement existant : association explicite ou erreur si nécessaire. |
| Plusieurs implémentations | Erreur à la résolution, avec les noms des candidates. |
| Répertoire absent ou namespace invalide | Erreur immédiate et contextualisée. |
| Échec pendant le parcours | Aucune candidate de cet appel n'est ajoutée au conteneur. |
| Échec d'un `load()` contenant `discover()` | Restauration des définitions et des candidates précédentes. |
| Appel après le premier `get()` | Erreur : la configuration est déjà figée. |

La découverte est entièrement optionnelle. Sans appel à `discover()`, les
applications existantes conservent leur fonctionnement. Préparer tous les choix
avant la première résolution ; après une erreur de construction qui a figé la
configuration, corriger celle-ci puis recréer le conteneur.
