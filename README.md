# GeoScan

Outil de renseignement réseau écrit en Laravel : il **scrape les pages publiques de
shodan.io** (résultats de recherche et fiches hôte) et conserve un **historique
daté** de tout ce qu'il a consulté.

L'objectif n'est pas d'appeler l'API Shodan, mais de maîtriser le scraping HTML :
requêtes HTTP, parsing DOM, et surtout la modélisation d'une donnée qui change dans
le temps.

> TP — IT-Akademy, séquence D11 (Laravel). Énoncé complet dans [`docs/TP-GeoScan.pdf`](docs/TP-GeoScan.pdf).

**L'application n'a ni compte ni page de connexion** : c'est un outil mono-opérateur
qu'on lance en local. La seule « connexion » du projet est celle vers Shodan, et elle
se configure dans `.env` — voir [Connexion à Shodan](#connexion-à-shodan-obligatoire).

---

## L'idée centrale : distinguer l'entité de son historique

Les fiches Shodan changent tous les jours. Une IP peut changer d'organisation,
d'ASN ou de ports ouverts entre deux visites. Toute la conception découle de là :

| Table | Rôle | Écriture |
|---|---|---|
| `hosts` | l'entité **stable** — une ligne par IP, jamais dupliquée | `firstOrCreate` |
| `host_snapshots` | l'**historique** — une ligne par consultation | insertion seule, **jamais d'update** |
| `searches` | une recherche archivée à un instant T | insertion seule |
| `search_facets` | les classements « Top » de cette recherche | insertion seule |

```
hosts 1 ──< host_snapshots        (hasMany / belongsTo)
searches 1 ──< search_facets      (hasMany / belongsTo)
```

Un instantané n'est **jamais modifié après coup**. C'est ce qui permet la ligne du
temps de la fiche hôte : si l'organisation d'une IP change, les deux versions
coexistent en base et la différence est mise en évidence à l'écran.

Corollaire assumé : consulter l'historique est une **lecture d'archive**, jamais un
nouveau scraping. Une recherche archivée réaffiche les chiffres tels qu'ils étaient,
même s'ils sont périmés. Des tests garantissent qu'aucune requête sortante ne part
de ces pages.

---

## Scraping responsable

Relevé de <https://www.shodan.io/robots.txt> le 31/08/2026 (copie dans
[`docs/shodan-robots.txt`](docs/shodan-robots.txt)) :

```
User-agent: *
Crawl-delay: 10
Disallow: /domain/
```

Ce que le code en fait, dans [`ShodanClient`](app/Services/Shodan/ShodanClient.php) —
le **seul** point du code autorisé à sortir sur le réseau :

| Règle | Mise en œuvre |
|---|---|
| S'identifier | en-tête `User-Agent` configurable, envoyé sur chaque requête |
| `Crawl-delay: 10` | délai minimum de 10 s entre deux requêtes (`SHODAN_REQUEST_DELAY`), mémorisé via le cache pour tenir d'une requête HTTP à l'autre |
| `Disallow: /domain/` | les chemins interdits sont refusés **avant** l'envoi — la requête ne part pas |
| Ne scraper que l'utile | cooldown de 5 min par IP (`SHODAN_HOST_COOLDOWN`) : deux visites rapprochées d'une même fiche ne déclenchent qu'une requête |
| Plafonner une campagne | un scan s'arrête à `SHODAN_MAX_REQUESTS_PER_RUN` requêtes sortantes |
| Rendre des comptes | chaque requête sortante est journalisée et consultable dans `/journal` |
| Développer sans marteler le serveur | les parsers sont mis au point contre des copies locales de vraies pages ([`tests/Fixtures/`](tests/Fixtures/)) |

Aucun test de la suite ne contacte le vrai shodan.io : tous utilisent `Http::fake()`
sous `Http::preventStrayRequests()`.

---

## Installation

Prérequis : PHP 8.4+, Composer. Pas de serveur de base de données — SQLite suffit.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
```

Puis, en une seule commande (serveur HTTP **+ worker de queue**) :

```bash
composer dev
```

L'application est sur <http://127.0.0.1:8000>. Il n'y a rien à saisir pour entrer :
aucun compte, aucun mot de passe.

> **Le worker de queue n'est pas optionnel.** Un scan dure plusieurs minutes (10 s
> entre chaque requête) : il est mis en file d'attente, pas exécuté dans la requête
> HTTP. Sans worker, un scan reste indéfiniment « en attente ». Si tu préfères deux
> terminaux séparés à `composer dev` :
>
> ```bash
> php artisan serve      # terminal 1
> php artisan queue:work # terminal 2
> ```

---

## Connexion à Shodan (obligatoire)

Shodan refuse les **filtres de recherche** (`country:`, `port:`, `org:`, `after:`…) à
un visiteur anonyme — il répond « Please log in to use search filters ». Or les scans
de GeoScan reposent entièrement sur ces filtres. Il faut donc donner à l'application
une session Shodan, dans `.env`.

### Voie 1 — le cookie de session (recommandée, marche avec un compte Google)

1. Se connecter à <https://www.shodan.io> dans un navigateur, normalement.
2. Ouvrir les **outils de développement** (F12) → onglet **Réseau**.
3. Recharger la page, cliquer sur n'importe quelle requête vers `shodan.io`.
4. Section **En-têtes de requête** → copier la **valeur complète** de l'en-tête
   `Cookie` (typiquement `polito="…"`, parfois suivi d'autres cookies).
5. La coller dans `.env` :

```dotenv
SHODAN_LOGIN_ENABLED=true
SHODAN_SESSION_COOKIE='polito="colle-ici-la-valeur-complete"'
```

Les guillemets simples autour de la valeur sont importants : le cookie contient
lui-même des guillemets doubles.

### Voie 2 — e-mail + mot de passe

Uniquement pour un compte Shodan qui a réellement un mot de passe (inutilisable si le
compte a été créé via « Se connecter avec Google ») : l'application rejoue le
formulaire de connexion et garde les cookies obtenus.

```dotenv
SHODAN_LOGIN_ENABLED=true
SHODAN_EMAIL=toi@example.com
SHODAN_PASSWORD=…
```

### Vérifier

```bash
php artisan geoscan:session
```

La commande envoie une requête filtrée de test et dit si la session est acceptée. Le
cookie l'emporte sur l'e-mail/mot de passe quand les deux sont renseignés.

> `.env` n'est **pas** versionné (`.gitignore`) : aucun identifiant Shodan ne se
> trouve dans ce dépôt. Le modèle à recopier est [`.env.example`](.env.example).

### Le reste de la configuration

Tout est dans [`config/geoscan.php`](config/geoscan.php), piloté depuis `.env` :

```dotenv
SHODAN_USER_AGENT="GeoScanBot/1.0 (+contact: toi@example.com)"
SHODAN_REQUEST_DELAY=10          # secondes entre deux requêtes (plancher du robots.txt)
SHODAN_HOST_COOLDOWN=300         # secondes avant de re-scraper une même fiche hôte
SHODAN_MAX_REQUESTS_PER_RUN=30   # plafond de requêtes pour un scan
GEOCODING_ENABLED=true           # résolution des villes pour la carte
```

Mets ton propre contact dans `SHODAN_USER_AGENT` : c'est le principe même d'un
scraper qui s'annonce.

---

## Utilisation

| Page | Route | Effet réseau |
|---|---|---|
| Nouveau scan | `/scans/nouveau` | met un scan en file d'attente (le worker scrape ensuite) |
| Scans | `/scans` | aucun |
| Résultats d'un scan (carte + filtres) | `/scans/{id}` | aucun, sauf géocodage d'une ville inconnue |
| Nouvelle recherche | `/recherches/nouvelle` | **scrape** shodan.io à la soumission |
| Historique des recherches | `/recherches` | aucun |
| Archive d'une recherche | `/recherches/{id}` | aucun |
| Fiches hôte | `/hotes` | aucun |
| Fiche d'une IP | `/hotes/{ip}` | **scrape**, sauf si le cooldown couvre la visite |
| Veilles | `/veilles` | aucun |
| Journal des requêtes sortantes | `/journal` | aucun |

Les routes qui dépensent du quota Shodan sont limitées en débit (20 req/min, et
**3/min** pour le lancement d'un scan) : c'est ce qui remplace la barrière d'un compte
utilisateur, puisque ce n'est pas la confidentialité qui est en jeu ici mais le quota.

### Scans, veilles et journal

- **Scan** : plutôt qu'une recherche unique plafonnée par Shodan à quelques résultats,
  un scan découpe la requête en sous-requêtes (par port, par tranche horaire…) et
  agrège les hôtes trouvés, dans la limite de `SHODAN_MAX_REQUESTS_PER_RUN`. Le
  résultat s'affiche sur une carte, avec facettes et score d'exposition.
- **Veille** : rejoue la même recherche à intervalle régulier et met en évidence les
  hôtes **apparus depuis le passage précédent**. Réveillée toutes les heures par le
  scheduler (`php artisan schedule:work`), chaque veille décidant selon son intervalle.
- **Journal** : la trace de chaque requête sortante (URL, statut, session utilisée).
  Il est public dans l'application, parce qu'une preuve de crawl responsable que seul
  son auteur peut lire ne prouve pas grand-chose.

---

## Architecture

```
app/
├── Exceptions/ScrapingException.php        erreurs de scraping, messages explicites
├── Models/                                 Search, SearchFacet, Host, HostSnapshot,
│                                           Scan, ScanResult, ScanStep, Watch, OutboundRequest
├── Jobs/RunScan.php                        exécute une campagne hors requête HTTP
├── Http/Controllers/                       Search, Host, Scan, Watch, Journal
└── Services/
    ├── Shodan/
    │   ├── ShodanClient.php                politique de crawl (UA, délai, robots.txt, journal)
    │   ├── ShodanSession.php               cookie ou formulaire de connexion
    │   ├── ScanRunner.php                  découpage de la requête en sous-requêtes
    │   ├── SearchScraper.php               récupérer → extraire → archiver
    │   ├── HostScraper.php                 idem + garde-fou du cooldown
    │   └── Parsers/                        HTML → tableaux (fonctions pures)
    ├── Geo/                                géocodage des villes, marqueurs de la carte
    └── Exposure/                           score d'exposition d'un hôte
```

Les **parsers sont des fonctions pures** : HTML en entrée, tableau en sortie, sans
réseau ni base. C'est ce qui les rend testables contre une page enregistrée, et ce
qui isole la partie la plus fragile du projet — celle qui casse le jour où Shodan
change son HTML.

Le parsing utilise `symfony/dom-crawler` avec des sélecteurs CSS, jamais d'expression
régulière sur du HTML brut.

### Deux pièges de parsing rencontrés

Le HTML réel est plus retors que prévu — les deux cas ci-dessous sont couverts par
des tests de non-régression :

1. **Les domaines collés.** Chaque domaine est dans son propre `<a>`, sans séparateur
   textuel : `text()` renvoyait `harvard.edukaltura.comone.one`. Il faut lire les
   balises une par une.
2. **Les noms d'hôte tronqués.** Le `<b>` n'entoure que la *partie domaine* du nom
   (`lifelabtenant.wireless.med.<b>harvard.edu</b>`), et les noms sont séparés par des
   `<br>`. Se fier au `<b>` réduisait chaque nom d'hôte à son domaine.

---

## Tests

```bash
php artisan test          # ou : vendor/bin/phpunit --testdox
```

**151 tests, 408 assertions**, sans aucune requête réseau réelle.

| Fichier | Ce qui est vérifié |
|---|---|
| `Unit/Parsers/SearchPageParserTest` | total, 5 groupes de classement, lien « More… » ignoré, page vide, structure inconnue |
| `Unit/Parsers/HostPageParserTest` | tous les champs de la fiche, listes multi-valeurs, page inconnue |
| `Unit/Parsers/FacetPageParserTest` | extraction des facettes d'une page de scan |
| `Unit/ExposureScorerTest` | calcul du score d'exposition |
| `Feature/ShodanClientTest` | User-Agent envoyé, `Disallow` respecté avant l'envoi, délai appliqué, mur de connexion, erreur HTTP |
| `Feature/ShodanSessionTest` | cookie prioritaire, rejeu du formulaire, session expirée |
| `Feature/SearchScraperTest` | archivage recherche + classements, pas d'écrasement |
| `Feature/HostCooldownTest` | **1 instantané** si deux visites rapprochées, **2** après expiration, instantanés jamais modifiés |
| `Feature/SearchHistoryTest` | consulter l'historique n'envoie **aucune** requête |
| `Feature/HostPageTest` | rendu de la fiche, ligne du temps, résilience si le scraping échoue |
| `Feature/ScanRunnerTest` / `ScanPageTest` | découpage, plafond de requêtes, rendu de la carte et des filtres |
| `Feature/WatchTest` | intervalle, hôtes nouvellement apparus |
| `Feature/ComplianceJournalTest` | chaque requête sortante laisse une trace |
| `Feature/AccessPolicyTest` | toutes les pages accessibles sans compte, lancement de scan limité à 3/min |
