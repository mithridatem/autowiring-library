# Audit de sécurité — frontières de confiance d'autowiring

Cette note décrit les comportements vérifiés par `tests/Security/SecurityBoundaryTest.php`.
Les scénarios sont des **tests de caractérisation** : ils ne constituent pas une
certification de sécurité et ne démontrent pas une vulnérabilité intrinsèque.

## Modèle de confiance

`Container` est un outil de **composition interne** de l'application, pas un
interpréteur de commandes venant du réseau.

| Entrée | Origine attendue | Risque si l'origine n'est pas fiable |
| --- | --- | --- |
| `get($id)` | Code PHP contrôlé par l'application | Une classe concrète autoloadable peut être construite à la demande, avec exécution de son constructeur et de ceux de ses dépendances |
| `load($file)` | Chemin vers un fichier PHP de configuration maîtrisé | `require` exécute le code PHP du fichier, même s'il ne retourne pas une Closure ou si sa configuration échoue |
| `discover($directory, $namespace)` | Répertoire et namespace PSR-4 maîtrisés | La découverte déclenche l'autoload des classes candidates ; l'autoload peut exécuter du code à son chargement |
| `factory($id, $factory)` | Callable ou factory enregistrés par le développeur | La factory exécute librement le code de l'application lors du premier `get()` |
| `configure($class, $parameters)` | Valeurs validées par l'application | Ces valeurs sont transmises au constructeur ; le conteneur ne remplace pas la validation métier |

### Exemple d'utilisation à éviter

```php
// Ne JAMAIS choisir une classe à construire directement à partir d'un paramètre HTTP.
$service = $container->get($_GET['service']);
```

Préférer une correspondance explicite, dans l'application, entre les actions
autorisées et leurs services :

```php
$services = [
    'books' => \App\Service\BookService::class,
    'users' => \App\Service\UserReadService::class,
];
$key = $_GET['service'] ?? '';
if (!is_string($key) || !isset($services[$key])) {
    throw new \InvalidArgumentException('Service non autorisé');
}
$service = $container->get($services[$key]);
```

**Ne pas** charger un fichier de configuration choisi depuis une URL, un
champ de formulaire, un upload ou un autre chemin contrôlé par un utilisateur.
Le test vérifie expressément que `load()` exécute le code PHP demandé :
il n'existe **pas de bac à sable**. Le rollback des définitions du conteneur
ne peut pas annuler des effets de bord PHP, comme une écriture disque.

Pour `discover()`, ne scanner que des répertoires versionnés et contrôlés
par le projet. Ne pas mélanger ces répertoires avec les uploads ou des fichiers
écrits par des utilisateurs. Le chargement automatique PSR-4 demeure une
responsabilité partagée avec le chargeur Composer.

## Garanties actuellement vérifiées

- `has()` ne construit pas d'objets, mais peut déclencher l'autoload de classes.
- Un `get()` explicite construit une classe concrète et met son instance en cache.
- Un identifiant inconnu déclenche une exception `NotFoundException`.
- Une factory qui retourne un objet de type incorrect est rejetée ; son résultat
  invalide n'est pas enregistré.
- Les définitions ne peuvent pas être modifiées pendant la résolution.
- Les modifications de définitions effectuées par une configuration échouée
  sont annulées, **mais pas ses effets de bord PHP**.
- Les objets sont partagés **par conteneur**, pas globalement. Pour des services
  avec état propre à la requête dans un worker persistant, utiliser un nouveau
  conteneur ou un cycle de vie approprié.

## Points à poursuivre

- Tests de découverte de classes avec répertoires non maîtrisés et autoloaders
  à effets de bord, uniquement dans un environnement de test isolé.
- Intégration avec le routeur : vérifier qu'aucune valeur HTTP ne peut être
  utilisée comme identifiant arbitraire de service via l'application.
- `composer audit` côté bibliothèque **et** dans les applications consommatrices.
- Évaluer la portée d'une configuration chargée depuis un chemin modifiable par
  un utilisateur ; ce cas est une faute d'intégration et non un besoin de
  modification automatique de `Container::load()`.

**Politique du projet :** pas de changement d'API ou de code de production
sans preuve d'une vulnérabilité structurelle dans un scénario d'utilisation
conforme à la documentation.


## Factories et `#[Inject]` — tests adversariaux complémentaires

Les scénarios du fichier `tests/Security/FactoryInjectSecurityTest.php`
caractérisent les frontières suivantes :

- Une factory enregistrée sous un nom de classe ou d'interface doit retourner
  un objet compatible avec ce type. Un résultat incorrect est rejeté et non
  mémorisé ; un appel suivant peut retenter la factory.
- Une factory implémentant `FactoryInterface` reçoit un conteneur PSR-11.
  Son résultat est partagé dans ce conteneur après une résolution réussie.
- Avant le premier `get()`, `set()` peut remplacer une factory. La factory
  remplacée ne doit jamais être appelée lors de la résolution.
- Les dépendances cycliques entre factories sont rejetées ; une erreur ne
  doit pas empêcher la résolution ultérieure de services indépendants.
- `#[Inject('service.selected')]` exige le service explicitement désigné :
  aucune bascule silencieuse vers une autre implémentation n'est prévue
  lorsque sa factory échoue.
- Même pour un identifiant libre (sans classe PHP correspondante), l'objet
  obtenu par `#[Inject]` doit respecter le type du paramètre de constructeur.
- `configure()` est prioritaire sur `#[Inject]`. Si une valeur configurée
  est incompatible avec le type PHP du constructeur, celui-ci échoue ;
  l'attribut ne constitue pas un chemin de secours.

### Responsabilités de l'application

Les closures et objets factories sont **du code applicatif de confiance**.
Le conteneur ne peut ni neutraliser leurs effets de bord, ni limiter leur
accès aux ressources, ni annuler une opération externe après une exception.
Les factories qui ouvrent des connexions, écrivent des fichiers ou appellent
des API doivent traiter les erreurs et la validation de leurs propres données.

Les identifiants `#[Inject]` sont déclarés dans le code du constructeur ;
ils ne doivent pas être dérivés indirectement d'une entrée HTTP non validée
dans la configuration des factories. Cette note décrit un contrat, pas
une vulnérabilité intrinsèque démontrée.
