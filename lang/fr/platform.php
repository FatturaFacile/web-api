<?php

declare(strict_types=1);

return [
    'idempotency' => [
        'title' => 'Problème de clé d\'idempotence',
        'invalid_key' => 'L\'en-tête Idempotency-Key doit être une chaîne non vide de 255 caractères au plus.',
        'key_reused' => 'Cette Idempotency-Key a déjà été utilisée pour une autre requête.',
        'in_progress' => 'Une requête avec cette Idempotency-Key est encore en cours de traitement.',
    ],
    'conflict' => [
        'title' => 'Conflit de modification',
        'detail' => 'L\'enregistrement a été modifié par quelqu\'un d\'autre depuis son ouverture. Rechargez-le et appliquez à nouveau vos modifications.',
    ],
    'recycle_bin' => [
        'not_purgeable' => 'Ce type d\'enregistrement doit être conservé pour des raisons légales et ne peut pas être détruit.',
        'in_use' => 'L\'enregistrement est encore référencé par d\'autres enregistrements et ne peut pas être détruit.',
    ],
];
