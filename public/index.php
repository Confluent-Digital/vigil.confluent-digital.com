<?php

declare(strict_types=1);

// Front controller du back-office. Le chemin chaud (/c, /pb) ne passe PAS par
// ici : nginx l'envoie directement vers c.php et pb.php.

require dirname(__DIR__) . '/vendor/autoload.php';

(require dirname(__DIR__) . '/src/app.php')->run();
