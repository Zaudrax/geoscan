<?php

/*
|--------------------------------------------------------------------------
| Exposure grid
|--------------------------------------------------------------------------
| This file is a JUDGEMENT, not a measurement. It states what we consider
| abnormal to find open on the public internet, and why. It lives in config
| precisely so that judgement stays readable, arguable and changeable without
| touching any code.
|
| What this file does NOT do: claim that a machine is vulnerable. An open port
| is not a flaw. "Exposure" means "attack surface offered", not "compromised".
| That distinction has to stay visible all the way into the interface.
|
| Note that the labels below are user facing, so they stay in French like the
| rest of the interface; only the reasoning is in English.
*/

return [

    /*
    | Levels, most severe first. The weight is used to rank a host by its worst
    | finding, never to add points up: the sum of ten trivia is not an
    | emergency.
    */
    'levels' => [
        'critique' => ['weight' => 3, 'label' => 'Critique'],
        'eleve' => ['weight' => 2, 'label' => 'Élevé'],
        'modere' => ['weight' => 1, 'label' => 'Modéré'],
        'info' => ['weight' => 0, 'label' => 'Pour information'],
    ],

    /*
    | Services that have no business being directly reachable from the internet.
    |
    | CRITICAL: remote administration, or databases historically shipped with no
    | authentication by default. A successful connection hands over the machine,
    | or all of its data.
    |
    | HIGH: cleartext protocols, or file shares. Credential interception, or
    | files exposed directly.
    */
    'ports' => [
        23 => ['level' => 'critique', 'service' => 'Telnet', 'why' => 'administration à distance en clair : identifiants interceptables, aucun chiffrement'],
        445 => ['level' => 'critique', 'service' => 'SMB', 'why' => 'partage de fichiers Windows exposé : cible historique des rançongiciels'],
        3389 => ['level' => 'critique', 'service' => 'RDP', 'why' => 'bureau à distance Windows exposé : cible privilégiée du bourrage d\'identifiants'],
        5900 => ['level' => 'critique', 'service' => 'VNC', 'why' => 'contrôle d\'écran à distance, souvent sans mot de passe'],
        6379 => ['level' => 'critique', 'service' => 'Redis', 'why' => 'base en mémoire sans authentification par défaut'],
        9200 => ['level' => 'critique', 'service' => 'Elasticsearch', 'why' => 'moteur d\'indexation sans authentification par défaut : fuite de données en masse'],
        11211 => ['level' => 'critique', 'service' => 'Memcached', 'why' => 'cache sans authentification, et amplificateur de déni de service'],
        27017 => ['level' => 'critique', 'service' => 'MongoDB', 'why' => 'base documentaire sans authentification par défaut'],

        21 => ['level' => 'eleve', 'service' => 'FTP', 'why' => 'transfert de fichiers en clair'],
        25 => ['level' => 'eleve', 'service' => 'SMTP', 'why' => 'relais de courrier : à vérifier qu\'il n\'est pas ouvert'],
        135 => ['level' => 'eleve', 'service' => 'MSRPC', 'why' => 'service interne Windows, jamais destiné à Internet'],
        139 => ['level' => 'eleve', 'service' => 'NetBIOS', 'why' => 'service interne Windows, jamais destiné à Internet'],
        1433 => ['level' => 'eleve', 'service' => 'MSSQL', 'why' => 'base de données directement joignable'],
        3306 => ['level' => 'eleve', 'service' => 'MySQL', 'why' => 'base de données directement joignable'],
        5432 => ['level' => 'eleve', 'service' => 'PostgreSQL', 'why' => 'base de données directement joignable'],
        5984 => ['level' => 'eleve', 'service' => 'CouchDB', 'why' => 'base documentaire directement joignable'],

        22 => ['level' => 'modere', 'service' => 'SSH', 'why' => 'administration à distance : légitime, mais à surveiller'],
        161 => ['level' => 'modere', 'service' => 'SNMP', 'why' => 'supervision réseau : divulgue la topologie si la communauté est celle par défaut'],
    ],

    /*
    | Tags applied by Shodan itself. They describe the machine rather than a
    | port, which is why they are graded separately.
    */
    'tags' => [
        'honeypot' => ['level' => 'info', 'why' => 'Shodan estime que cette machine est un leurre : les données sont probablement fabriquées'],
        'compromised' => ['level' => 'critique', 'why' => 'Shodan a relevé des signes de compromission'],
        'malware' => ['level' => 'critique', 'why' => 'Shodan a relevé la présence d\'un logiciel malveillant'],
        'self-signed' => ['level' => 'modere', 'why' => 'certificat auto-signé : aucune autorité ne garantit l\'identité du serveur'],
        'expired' => ['level' => 'modere', 'why' => 'certificat expiré'],
        'starttls' => ['level' => 'info', 'why' => 'chiffrement optionnel : une session peut rester en clair'],
        'ics' => ['level' => 'critique', 'why' => 'système industriel exposé : les conséquences d\'une intrusion sont physiques'],
        'scada' => ['level' => 'critique', 'why' => 'supervision industrielle exposée : les conséquences d\'une intrusion sont physiques'],
    ],

    /*
    | Patterns matched against the raw banner.
    |
    | A banner announcing its version number does half of an attacker's work: it
    | saves them from guessing which flaw to try.
    */
    'banner_patterns' => [
        [
            'pattern' => '/^Server:\s*(?<detail>[A-Za-z][\w .\/-]*\/\d+\.[\d.]+)/mi',
            'level' => 'modere',
            'title' => 'Version de serveur divulguée',
            'why' => 'la bannière annonce la version exacte : un attaquant sait quelles failles essayer sans tâtonner',
        ],
        [
            'pattern' => '/^HTTP\/[\d.]+ 401 /mi',
            'level' => 'info',
            'title' => 'Authentification demandée',
            'why' => 'le service demande une authentification : c\'est bon signe',
        ],
        [
            'pattern' => '/(?<detail>WWW-Authenticate:\s*Basic)/mi',
            'level' => 'eleve',
            'title' => 'Authentification HTTP Basic',
            'why' => 'identifiants transmis en clair (simple encodage base64) si la connexion n\'est pas en TLS',
        ],
        /*
         * An embedded device reachable from the internet.
         *
         * This is where the real signal is, and it took measuring to realise
         * it: an earlier version flagged every 200 response as "access without
         * authentication". On a real scan of 181 services, 180 raised that
         * finding -- a column that lights up everywhere stops informing.
         * Answering 200 is the normal behaviour of the web; what matters is the
         * NATURE of the server.
         *
         * These strings identify device web servers: rarely updated, often
         * shipped with default credentials, and never designed to be exposed
         * directly.
         */
        [
            'pattern' => '/^Server:\s*(?<detail>yawcam|webcamXP|webcam ?7|Boa\b|GoAhead|thttpd|mini_httpd|uc-httpd|Hikvision|Dahua|RomPager|Router)/mi',
            'level' => 'eleve',
            'title' => 'Équipement embarqué joignable',
            'why' => 'serveur web d\'équipement (caméra, routeur, enregistreur) : rarement mis à jour et souvent livré avec des identifiants par défaut',
        ],
        [
            'pattern' => '/^HTTP\/[\d.]+ 200 /mi',
            'level' => 'info',
            'title' => 'Réponse servie sans authentification',
            'why' => 'le service répond sans rien demander : normal pour un site public, anormal pour un équipement',
        ],
    ],
];
