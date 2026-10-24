# Historique des changements

## [Unreleased]

Aucun changement supplémentaire annoncé.

## [1.2.0] - 2026-10-24

### Ajouts

- Découverte optionnelle des implémentations via un répertoire et un namespace PSR-4.
- Association des interfaces avec une seule candidate et diagnostic des ambiguïtés.
- Priorité aux définitions explicites, déduplication des parcours et intégration à `load()`.
- Exemple de routing, guide de découverte et contrôles unitaires/de distribution.

### Documentation

- Actualisation des guides et des commandes Composer pour la release 1.2.0.

La découverte est introduite dans cette version ; les fonctionnalités 1.1 restent compatibles.

## [1.1.0] - 2026-10-24

### Ajouts

- Attribut `#[Inject]` pour sélectionner un service objet dans un constructeur,
  avec priorité à `configure()` et vérification du type attendu.
- Chargement de fichiers `config/services.php` retournant une Closure de configuration.
- Détection des imports circulaires et restauration des définitions si un chargement échoue.
- Exemple exécutable, tests unitaires, intégration au routeur et contrôle de l'archive.

### Documentation

- Guide des attributs et de la configuration centralisée, avec exemples et dépannage.
- Présentation simplifiée de l'installation Composer dans le README.

## [1.0.2] - 2026-10-23

- Même contenu source que le tag 1.0.1 ; aucune modification fonctionnelle supplémentaire.

## [1.0.1] - 2026-10-23

### Documentation

- Installation stable depuis Packagist, guide d'intégration PDO et procédure de maintenance.
- Documentation de la première publication et guide d'utilisation applicatif.

## [1.0.0] - 2026-10-23

Première version stable, disponible sur Packagist.

### Ajouts

- Conteneur d'objets compatible PSR-11 avec injection par constructeur.
- Résolution récursive des classes concrètes et associations interface/implémentation.
- Configuration des paramètres nommés, enregistrement d'instances et de fabriques.
- Instances partagées, détection des cycles et exceptions avec conservation des causes.
- Adaptateur optionnel pour Mithridatem Router et exemples d'utilisation.
- Tests unitaires et d'intégration, exécutés sur PHP 8.1, 8.3 et 8.4.
- Métadonnées Composer, archives allégées et procédure de publication sur Packagist.
- Vérification de l'installation de l'archive comme dépendance de production.

[Unreleased]: https://github.com/mithridatem/autowiring-library/compare/v1.2.0...main
[1.2.0]: https://github.com/mithridatem/autowiring-library/compare/v1.1.0...v1.2.0
[1.1.0]: https://github.com/mithridatem/autowiring-library/compare/v1.0.2...v1.1.0
[1.0.2]: https://github.com/mithridatem/autowiring-library/compare/v1.0.1...v1.0.2
[1.0.1]: https://github.com/mithridatem/autowiring-library/compare/v1.0.0...v1.0.1
[1.0.0]: https://github.com/mithridatem/autowiring-library/tree/v1.0.0
