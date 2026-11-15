<?php

declare(strict_types=1);

return [
    'carrier' => [
        'in_use' => 'Le transporteur a encore des ordres de transport ou des tournees ouverts.',
    ],
    'tariff' => [
        'valid_to_before_from' => 'La grille tarifaire ne peut pas se terminer avant son debut.',
        'range_order' => 'La borne superieure d\'une plage doit etre superieure a sa borne inferieure.',
        'band_overlap' => 'La tranche chevauche une autre tranche de cette grille pour les memes zones (poids de :from a :to).',
    ],
    'rate' => [
        'no_rate' => 'Aucun tarif actif de :carrier ne couvre cet envoi.',
    ],
    'order' => [
        'window_order' => 'Une fenetre ne peut pas se terminer avant son debut.',
        'not_editable' => 'Un ordre de transport au statut :status ne peut plus etre modifie.',
        'not_deletable' => 'Un ordre de transport au statut :status ne peut pas etre supprime : annulez-le.',
        'invalid_transition' => 'L\'ordre de transport est au statut :status : cette etape n\'est pas possible.',
        'carrier_inactive' => 'Le transporteur :carrier n\'est pas actif.',
        'trip_carrier' => 'L\'ordre est sur une tournee d\'un autre transporteur.',
        'on_trip' => 'L\'ordre est sur une tournee : retirez-le d\'abord de la tournee.',
    ],
    'trip' => [
        'ends_before_start' => 'La tournee ne peut pas se terminer avant son debut.',
        'not_editable' => 'Une tournee au statut :status ne peut plus etre modifiee.',
        'capacity_below_load' => 'La capacite ne peut pas descendre sous la charge deja consolidee (:load).',
        'not_deletable' => 'Seule une tournee planifiee sans ordres peut etre supprimee.',
        'order_not_found' => 'L\'un des ordres n\'existe pas dans cette entreprise.',
        'order_not_planned' => 'L\'ordre :number est au statut :status : seuls les ordres planifies peuvent etre consolides.',
        'order_on_trip' => 'L\'ordre :number est deja sur une tournee.',
        'order_other_carrier' => 'L\'ordre :number appartient a un autre transporteur que la tournee.',
        'over_weight' => 'La charge de :total kg depasse la capacite de :capacity kg.',
        'over_volume' => 'La charge de :total m3 depasse la capacite de :capacity m3.',
        'order_not_on_trip' => 'L\'ordre n\'est pas sur cette tournee.',
        'stops_mismatch' => 'La liste doit contenir chaque arret de la tournee exactement une fois.',
        'delivery_before_pickup' => 'Une livraison ne peut pas preceder l\'enlevement du meme ordre.',
        'stop_not_found' => 'L\'arret n\'appartient pas a cette tournee.',
        'stop_done' => 'L\'arret est deja termine.',
        'stop_out_of_sequence' => 'Des arrets precedents sont encore en attente : terminez-les d\'abord.',
        'departure_before_arrival' => 'Le depart ne peut pas etre avant l\'arrivee.',
        'no_stops' => 'Une tournee sans arrets ne peut pas etre expediee.',
        'stops_pending' => ':count arrets de la tournee sont encore en attente.',
        'invalid_transition' => 'La tournee est au statut :status : cette etape n\'est pas possible.',
    ],
    'tracking' => [
        'order_closed' => 'L\'ordre :number est au statut :status : il n\'accepte pas d\'evenements de suivi.',
    ],
    'quote' => [
        'no_offers' => 'Aucun transporteur actif n\'a de tarif pour cet envoi.',
        'not_open' => 'Le devis est deja au statut :status.',
        'not_offered' => 'Ce transporteur n\'a fait aucune offre dans le devis.',
        'awarded_locked' => 'Un devis attribue ne peut pas etre supprime.',
    ],
    'invoice' => [
        'disputed' => 'La facture est en litige ou deja reglee : elle ne peut pas etre modifiee ni supprimee.',
        'resolved' => 'Le litige est regle : la facture ne peut plus etre auditee.',
        'nothing_to_dispute' => 'Auditez d\'abord la facture : seule une facture surfacturee, sous-facturee ou non rapprochee peut etre contestee.',
        'dispute_exists' => 'La facture a deja un litige (:status).',
        'no_open_dispute' => 'La facture n\'a pas de litige ouvert.',
        'credit_range' => 'Le montant credite ne peut pas depasser :limit.',
        'credit_only_when_credited' => 'Un montant credite n\'est possible qu\'avec la resolution creditee.',
        'order_other_carrier' => 'L\'ordre de transport n\'appartient pas au transporteur de la facture.',
    ],
];
