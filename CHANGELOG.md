# Historique des changements

## [Unreleased]

### Documentation

- Installation stable depuis Packagist et vérification de la version installée.
- Guide de maintenance pour les releases suivant la première version stable.
- Guide d'utilisation avec intégration du routeur, injection PDO et dépannage.

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

[Unreleased]: https://github.com/mithridatem/autowiring-library/compare/v1.0.0...main
[1.0.0]: https://github.com/mithridatem/autowiring-library/tree/v1.0.0
