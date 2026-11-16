<?php

declare(strict_types=1);

return [
    'carrier' => [
        'in_use' => 'Le transporteur a encore des ordres de transport ou des tournées ouverts.',
    ],
    'tariff' => [
        'valid_to_before_from' => 'La grille tarifaire ne peut pas se terminer avant son début.',
        'range_order' => 'La borne supérieure d\'une plage doit être supérieure à sa borne inférieure.',
        'band_overlap' => 'La tranche chevauche une autre tranche de cette grille pour les mêmes zones (poids de :from à :to).',
    ],
    'rate' => [
        'no_rate' => 'Aucun tarif actif de :carrier ne couvre cet envoi.',
    ],
    'order' => [
        'window_order' => 'Une fenêtre ne peut pas se terminer avant son début.',
        'not_editable' => 'Un ordre de transport au statut :status ne peut plus être modifié.',
        'not_deletable' => 'Un ordre de transport au statut :status ne peut pas être supprimé : annulez-le.',
        'invalid_transition' => 'L\'ordre de transport est au statut :status : cette étape n\'est pas possible.',
        'carrier_inactive' => 'Le transporteur :carrier n\'est pas actif.',
        'trip_carrier' => 'L\'ordre est sur une tournée d\'un autre transporteur.',
        'on_trip' => 'L\'ordre est sur une tournée : retirez-le d\'abord de la tournée.',
    ],
    'trip' => [
        'ends_before_start' => 'La tournée ne peut pas se terminer avant son début.',
        'not_editable' => 'Une tournée au statut :status ne peut plus être modifiée.',
        'capacity_below_load' => 'La capacité ne peut pas descendre sous la charge déjà consolidée (:load).',
        'not_deletable' => 'Seule une tournée planifiee sans ordres peut être supprimée.',
        'order_not_found' => 'L\'un des ordres n\'existe pas dans cette entreprise.',
        'order_not_planned' => 'L\'ordre :number est au statut :status : seuls les ordres planifiés peuvent être consolidés.',
        'order_on_trip' => 'L\'ordre :number est déjà sur une tournée.',
        'order_other_carrier' => 'L\'ordre :number appartient à un autre transporteur que la tournée.',
        'over_weight' => 'La charge de :total kg dépasse la capacité de :capacity kg.',
        'over_volume' => 'La charge de :total m3 dépasse la capacité de :capacity m3.',
        'order_not_on_trip' => 'L\'ordre n\'est pas sur cette tournée.',
        'stops_mismatch' => 'La liste doit contenir chaque arrêt de la tournée exactement une fois.',
        'delivery_before_pickup' => 'Une livraison ne peut pas précéder l\'enlèvement du même ordre.',
        'stop_not_found' => 'L\'arrêt n\'appartient pas à cette tournée.',
        'stop_done' => 'L\'arrêt est déjà terminé.',
        'stop_out_of_sequence' => 'Des arrêts précédents sont encore en attente : terminez-les d\'abord.',
        'departure_before_arrival' => 'Le départ ne peut pas être avant l\'arrivee.',
        'no_stops' => 'Une tournée sans arrêts ne peut pas être expédiée.',
        'stops_pending' => ':count arrêts de la tournée sont encore en attente.',
        'invalid_transition' => 'La tournée est au statut :status : cette étape n\'est pas possible.',
    ],
    'tracking' => [
        'order_closed' => 'L\'ordre :number est au statut :status : il n\'accepte pas d\'événements de suivi.',
    ],
    'quote' => [
        'no_offers' => 'Aucun transporteur actif n\'a de tarif pour cet envoi.',
        'not_open' => 'Le devis est déjà au statut :status.',
        'not_offered' => 'Ce transporteur n\'a fait aucune offre dans le devis.',
        'awarded_locked' => 'Un devis attribué ne peut pas être supprimé.',
    ],
    'invoice' => [
        'disputed' => 'La facture est en litige ou déjà réglée : elle ne peut pas être modifiée ni supprimée.',
        'resolved' => 'Le litige est réglé : la facture ne peut plus être auditée.',
        'nothing_to_dispute' => 'Auditez d\'abord la facture : seule une facture surfacturée, sous-facturée ou non rapprochée peut être contestée.',
        'dispute_exists' => 'La facture a déjà un litige (:status).',
        'no_open_dispute' => 'La facture n\'a pas de litige ouvert.',
        'credit_range' => 'Le montant crédité ne peut pas dépasser :limit.',
        'credit_only_when_credited' => 'Un montant crédité n\'est possible qu\'avec la résolution créditée.',
        'order_other_carrier' => 'L\'ordre de transport n\'appartient pas au transporteur de la facture.',
    ],
];
