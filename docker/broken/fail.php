<?php

// exécuté avant chaque page (auto_prepend_file) : simule une version boguée.
// /health.php reste correct : les pods sont "prêts", c'est l'analyse des métriques
// par Flagger qui doit détecter le problème.
if ($_SERVER['SCRIPT_NAME'] !== '/health.php' && random_int(0, 1) === 0) {
    http_response_code(500);
    exit('Erreur simulée (version 1.1.1-broken)');
}
