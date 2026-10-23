# Maintenir et publier Mithridatem Autowiring

## Version publiée

La première version stable **v1.0.0**, datée du **23 octobre 2026**, est disponible
sur [Packagist](https://packagist.org/packages/mithridatem/autowiring).
Son code source est consultable sur le [tag v1.0.0](https://github.com/mithridatem/autowiring-library/tree/v1.0.0).
Le dépôt est déjà enregistré sur Packagist et son webhook GitHub est configuré.
Il n'est pas nécessaire de soumettre de nouveau le dépôt pour les prochaines versions.

Le paquet porte le nom Composer `mithridatem/autowiring`. Le nom du dépôt GitHub
`autowiring-library` peut être différent : le champ `name` de `composer.json`
détermine la commande d'installation.

## Installer et vérifier la version stable

Depuis un projet d'application :

```bash
composer require mithridatem/autowiring:^1.0
composer show mithridatem/autowiring
```

Pour utiliser l'adaptateur de routing, installer aussi le routeur :

```bash
composer require mithridatem/router:^1.2
```

Le cœur requiert uniquement PHP et `psr/container`. Le routeur est optionnel.
Les exemples et les tests ne sont pas inclus dans l'archive de distribution ;
cloner le dépôt et installer les dépendances de développement pour les exécuter.

## Préparer les prochaines releases

1. Consigner les évolutions dans la section `Unreleased` de `CHANGELOG.md`.
2. Choisir une version selon les changements : correctif compatible (par exemple
   `1.0.1`), fonctionnalité compatible (`1.1.0`) ou rupture de l'API (`2.0.0`).
3. Avant la release, transformer les éléments concernés de `Unreleased` en une
   section datée portant la version choisie et conserver une section `Unreleased`
   pour la suite. Mettre à jour les liens de comparaison en bas du changelog.
4. Faire relire et fusionner ces changements, puis vérifier la branche `main` :

```bash
git switch main
git pull --ff-only
composer validate --strict
composer install
composer check
git status --short
git tag --list
```

Attendre la réussite de la CI et vérifier que le répertoire de travail est propre.
La CI vérifie les tests sur PHP 8.1, 8.3 et 8.4 et l'installation de l'archive dans
une application indépendante sans dépendances de développement.

Créer ensuite le nouveau tag. Exemple pour une **future** version corrective,
uniquement si `v1.0.1` est bien la version choisie et n'existe pas encore :

```bash
git tag -a v1.0.1 -m "Release v1.0.1"
git push origin v1.0.1
```

Ne pas recréer ou déplacer `v1.0.0`. Les mises à jour de documentation sur `main`
ne modifient pas le contenu déjà distribué sous ce tag. Pour distribuer ces
changements avec le paquet, les inclure dans une prochaine release.
Ne pas ajouter de champ `version` dans `composer.json` : Composer déduit les
versions des tags Git.

## Vérifier la synchronisation Packagist

Après le push du nouveau tag :

1. Dans GitHub, ouvrir **Settings → Webhooks → webhook Packagist → Recent Deliveries**
   et vérifier la réussite de la livraison liée au push.
2. Sur la [page du paquet](https://packagist.org/packages/mithridatem/autowiring),
   vérifier que la nouvelle version apparaît.
3. Si nécessaire, utiliser le bouton **Update** depuis le compte mainteneur pour
   relancer l'indexation. En cas d'échec, examiner le message avant de réessayer.
4. Dans une application de test, lancer `composer update mithridatem/autowiring`,
   puis `composer show mithridatem/autowiring` pour contrôler la version obtenue.

La contrainte de l'application doit autoriser la nouvelle version : `^1.0`
accepte les versions compatibles de la branche 1.x, mais pas une version 2.x.
Ne pas ajouter de jeton Packagist au code, aux exemples ou à l'URL du dépôt.

## Contrôler l'archive de distribution localement

Depuis un clone propre, sur le commit à distribuer :

```bash
mkdir -p .build
git archive --format=zip --output=.build/autowiring.zip HEAD
php scripts/check-package.php .build/autowiring.zip
composer install --working-dir=.build/consumer --no-dev --no-interaction --prefer-dist --no-plugins --no-scripts
php .build/consumer/smoke.php
```

Ce contrôle nécessite l'extension PHP `zip`. Il utilise une version synthétique
`1.0.0` dans un dépôt Composer local temporaire, indépendamment de la version
réelle de l'archive. Il ne publie rien et ne contacte pas Packagist pour télécharger
la librairie testée ; ses dépendances sont résolues normalement par Composer.
Utiliser un nouveau répertoire de consommateur pour chaque nouvelle archive afin
d'éviter de réutiliser une installation ou un lockfile issus d'un contrôle précédent.

Références officielles : [librairies Composer](https://getcomposer.org/doc/02-libraries.md),
[schéma Composer](https://getcomposer.org/doc/04-schema.md),
[publication et synchronisation Packagist](https://packagist.org/about).
