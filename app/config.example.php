<?php
// Copier sous app/config.php. Ne jamais remplacer config.php lors d'une mise à jour.
return [
    'db_host' => 'localhost',
    'db_port' => 3306,
    'db_name' => 'CPANEL_cmyk',
    'db_user' => 'CPANEL_cmykuser',
    'db_password' => 'VOTRE_MOT_DE_PASSE_MYSQL',
    // Remplacez la valeur ci-dessous par au moins 32 caractères aléatoires.
    // Ce jeton sert uniquement à autoriser l'installation initiale.
    'setup_token' => 'CHANGE_ME_RANDOM_32_CHARACTERS_MINIMUM',
    'timezone' => 'Africa/Casablanca',
];
