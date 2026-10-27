<?php

// config.php utilisé dans le conteneur : les identifiants de la base viennent
// des variables d'environnement (docker compose, ou ConfigMap + Secret dans Kubernetes)
return [
    'host' => getenv('DB_HOST') ?: 'localhost',
    'dbname' => getenv('DB_NAME') ?: 'tomtroc',
    'user' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASSWORD') ?: '',
];
