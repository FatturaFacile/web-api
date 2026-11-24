<?php

declare(strict_types=1);

return [
    'attachment' => [
        'file_missing' => 'Le fichier de cette pièce jointe est introuvable dans le stockage.',
    ],
    'link' => [
        'record_not_found' => 'Cet enregistrement n\'existe pas.',
    ],
    'document' => [
        'checked_out' => 'Ce document est extrait : réintégrez-le ou annulez d\'abord l\'extraction.',
        'under_retention' => 'Ce document est soumis à conservation jusqu\'au :date.',
        'not_checked_out' => 'Ce document n\'est pas extrait.',
        'checked_out_by_other' => 'Ce document est extrait par un autre utilisateur.',
        'legal_hold' => 'Ce document fait l\'objet d\'une conservation légale et ne peut pas être modifié.',
    ],
    'approval' => [
        'template_inactive' => 'Le modèle d\'approbation n\'est pas actif.',
        'template_without_steps' => 'Le modèle d\'approbation n\'a aucune étape.',
        'already_pending' => 'Ce document a déjà une demande d\'approbation en cours.',
        'not_pending' => 'Cette demande d\'approbation n\'est plus en cours.',
        'not_approver' => 'Vous n\'êtes pas l\'approbateur de l\'étape en cours.',
        'cannot_cancel' => 'Seul le demandeur ou un administrateur peut annuler cette demande.',
        'template_in_use' => 'Le circuit d\'approbation ne peut pas changer tant qu\'une demande est en cours.',
        'role_cannot_decide' => 'Ce rôle ne peut pas décider d\'une étape : il n\'a pas d\'accès en écriture aux documents.',
    ],
    'preservation' => [
        'no_hash' => 'Un paquet de conservation n\'a pas de hash à chaîner.',
    ],
    'ocr' => [
        'unsupported' => 'Aucun moteur OCR ne prend en charge ce format.',
    ],
];
