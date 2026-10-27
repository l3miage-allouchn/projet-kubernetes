<?php

// utilisé par les probes Kubernetes : répond "ok" si Apache et PHP fonctionnent.
// Volontairement sans accès à la base ni aux sessions : si la base a un souci,
// redémarrer les pods de l'application n'arrangerait rien.
header('Content-Type: text/plain');
header('Cache-Control: no-store');
echo 'ok';
