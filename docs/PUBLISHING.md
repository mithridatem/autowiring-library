# Publier Mithridatem Autowiring sur Packagist

Le paquet porte le nom Composer `mithridatem/autowiring`. Le nom du dépôt GitHub
`autowiring-library` peut être différent : c'est le champ `name` de `composer.json`
qui détermine la commande d'installation.

## 1. Vérifier le contenu avant la release

Depuis un clone propre du dépôt avec PHP 8.1 ou supérieur et Composer :

```bash
composer validate --strict
composer install
composer check
```

Attendre aussi la réussite de la CI. Le code public se trouve dans `src/` avec
l'autoload PSR-4 `Mithridatem\Autowiring\`. Le cœur requiert uniquement PHP et
`psr/container` ; le routeur est optionnel pour les utilisateurs du conteneur.

La CI génère une archive Git, contrôle les fichiers distribués et installe cette
archive dans une application consommatrice sans dépendances de développement.
Les dossiers exclus par `.gitattributes` restent disponibles dans le dépôt source.

Pour reproduire ce contrôle localement, sur le commit contenant les changements :

```bash
mkdir -p .build
git archive --format=zip --output=.build/autowiring.zip HEAD
php scripts/check-package.php .build/autowiring.zip
composer install --working-dir=.build/consumer --no-dev --no-interaction --prefer-dist --no-plugins --no-scripts
php .build/consumer/smoke.php
```

Ce contrôle nécessite l'extension PHP `zip`. Il utilise une version `1.0.0`
**uniquement dans un dépôt Composer local temporaire**, pour tester la résolution
d'une version stable. Il ne crée ni tag ni version sur Packagist.
Le fichier `.build/consumer/composer.lock` peut être supprimé avant une nouvelle
exécution si la résolution doit être recalculée.

## 2. Préparer et créer un tag stable

Après revue et fusion des changements :

1. Choisir une version selon la portée des changements. Pour une première API
   stable, `v1.0.0` est une possibilité ; vérifier d'abord que ce tag n'existe pas.
2. Dans `CHANGELOG.md`, remplacer `Unreleased` par une section avec la version et
   la date de release, puis ajouter une nouvelle section `Unreleased` vide.
3. Commiter et faire intégrer cette mise à jour, puis reprendre un clone à jour :

```bash
git switch main
git pull --ff-only
composer validate --strict
composer install
composer check
git status --short
```

Vérifier que le répertoire de travail est propre, puis créer le tag décidé :

```bash
git tag -a v1.0.0 -m "Release v1.0.0"
git push origin v1.0.0
```

Ne pas ajouter de champ `version` dans `composer.json` : Composer déduit la version
des tags Git. Ne pas déplacer un tag déjà publié ; créer une nouvelle version
corrective si nécessaire. Aucun tag n'est créé par la PR de préparation du paquet.

## 3. Référencer le dépôt sur Packagist

1. Se connecter à [Packagist](https://packagist.org/).
2. Ouvrir [Submit](https://packagist.org/packages/submit).
3. Indiquer `https://github.com/mithridatem/autowiring-library`.
4. Vérifier le nom `mithridatem/autowiring` et valider la soumission.
5. Vérifier que le tag stable est indexé et configurer la synchronisation GitHub
   proposée par Packagist, ou suivre sa documentation de mise à jour automatique.

La publication nécessite l'accès au compte Packagist concerné. Cette procédure
n'introduit pas de jeton de publication dans le dépôt ou dans la CI.

## 4. Vérifier depuis une application indépendante

Après indexation de la première version stable :

```bash
composer require mithridatem/autowiring:^1.0
```

Pour l'intégration au routeur :

```bash
composer require mithridatem/router:^1.2
```

Vérifier que les classes du conteneur sont accessibles via `vendor/autoload.php`.
Les exemples et les tests ne font pas partie de l'archive installée ; ils sont
consultables dans le dépôt GitHub.

Références officielles : [librairies Composer](https://getcomposer.org/doc/02-libraries.md),
[schéma Composer](https://getcomposer.org/doc/04-schema.md),
[publication Packagist](https://packagist.org/about).
