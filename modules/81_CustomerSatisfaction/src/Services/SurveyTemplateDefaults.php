<?php

declare(strict_types=1);

namespace Modules\CustomerSatisfaction\Services;

use Modules\CustomerSatisfaction\Models\SurveyTemplate;

/**
 * The stock survey templates of Mod 245: NPS, CSAT and CES wired to the five ERP trigger events, with the invitation texts and the
 * dynamic follow-up question of every score band in it-CH, de-CH, fr-CH, en and it-IT (the Italian text serves it-CH and it-IT).
 * Installing is idempotent by code: a template the company already has (even edited) is left alone.
 */
final class SurveyTemplateDefaults
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function definitions(): array
    {
        return [
            self::template('NPS-TICKET-CLOSED', 'nps', 'ticket_closed', ['email', 'sms'], 2, false, 'Post-ticket NPS', 'Net Promoter Score after a helpdesk ticket is closed'),
            self::template('CSAT-ORDER-DELIVERED', 'csat', 'order_delivered', ['email', 'sms', 'whatsapp'], 24, false, 'Post-delivery CSAT', 'Satisfaction with an order after delivery'),
            self::template('CES-FIELD-INTERVENTION', 'ces', 'field_intervention_signed', ['email', 'sms'], 4, true, 'Field intervention CES', 'Customer effort after a signed field intervention'),
            self::template('CSAT-ONBOARDING', 'csat', 'onboarding_completed', ['email'], 48, false, 'Onboarding CSAT', 'Satisfaction at the end of the onboarding'),
            self::template('NPS-SEMI-ANNUAL', 'nps', 'semi_annual_active_client', ['email'], 0, false, 'Semi-annual relationship NPS', 'Relationship NPS of the active clients, every six months'),
        ];
    }

    /**
     * Creates the missing stock templates in the active company.
     *
     * @return int the number of templates created
     */
    public static function install(): int
    {
        $created = 0;

        foreach (self::definitions() as $definition) {
            if (SurveyTemplate::query()->where('code', $definition['code'])->exists()) {
                continue;
            }

            SurveyTemplate::query()->create($definition);
            $created++;
        }

        return $created;
    }

    /**
     * @param  list<string>  $channels
     * @return array<string, mixed>
     */
    private static function template(string $code, string $type, string $event, array $channels, int $delayHours, bool $voice, string $name, string $description): array
    {
        return [
            'code' => $code,
            'name' => $name,
            'description' => $description,
            'survey_type' => $type,
            'trigger_event' => $event,
            'trigger_filters' => [],
            'priority' => 100,
            'channels' => $channels,
            'delay_hours' => $delayHours,
            'cooldown_days' => 60,
            'detractor_threshold' => null,
            'sla_hours' => 24,
            'allow_voice_comment' => $voice,
            'messages' => self::messages($type, $event),
            'follow_up_config' => self::followUps($type),
            'dimensions' => ['department_code' => null, 'product_line_code' => null, 'branch_code' => null],
            'is_active' => true,
        ];
    }

    /**
     * @return array<string, array{subject: string, body: string, one_click_label: string}>
     */
    private static function messages(string $type, string $event): array
    {
        $subject = [
            'ticket_closed' => ['La sua richiesta è stata chiusa: com\'è andata?', 'Your request is closed: how did we do?', 'Ihre Anfrage wurde abgeschlossen: wie war es?', 'Votre demande est clôturée : comment cela s\'est-il passé ?'],
            'order_delivered' => ['Il suo ordine è stato consegnato', 'Your order has been delivered', 'Ihre Bestellung wurde geliefert', 'Votre commande a été livrée'],
            'field_intervention_signed' => ['Grazie per averci accolto: com\'è andato l\'intervento?', 'Thank you for having us: how was the visit?', 'Danke für Ihren Empfang: wie war der Einsatz?', 'Merci de votre accueil : comment s\'est passée l\'intervention ?'],
            'onboarding_completed' => ['Benvenuto a bordo: com\'è andato l\'avvio?', 'Welcome aboard: how was the onboarding?', 'Willkommen an Bord: wie war der Start?', 'Bienvenue à bord : comment s\'est passé le démarrage ?'],
            'semi_annual_active_client' => ['Due minuti per dirci cosa ne pensa', 'Two minutes to tell us what you think', 'Zwei Minuten für Ihre Meinung', 'Deux minutes pour nous donner votre avis'],
        ][$event];

        $body = [
            'nps' => [
                'Gentile {customer_name}, quanto è probabile che consiglierebbe la nostra azienda a un collega o a un amico? Basta un clic su un punteggio da 0 (per niente probabile) a 10 (molto probabile).',
                'Dear {customer_name}, how likely are you to recommend our company to a colleague or a friend? One click on a score from 0 (not at all likely) to 10 (extremely likely) is enough.',
                'Guten Tag {customer_name}, wie wahrscheinlich ist es, dass Sie unser Unternehmen einem Kollegen oder Freund weiterempfehlen? Ein Klick auf eine Bewertung von 0 (sehr unwahrscheinlich) bis 10 (sehr wahrscheinlich) genügt.',
                'Bonjour {customer_name}, quelle est la probabilité que vous recommandiez notre entreprise à un collègue ou à un ami ? Un clic sur une note de 0 (pas du tout probable) à 10 (très probable) suffit.',
            ],
            'csat' => [
                'Gentile {customer_name}, quanto è soddisfatto della nostra prestazione? Basta un clic su un punteggio da 1 (per niente soddisfatto) a 5 (molto soddisfatto).',
                'Dear {customer_name}, how satisfied are you with our service? One click on a score from 1 (very dissatisfied) to 5 (very satisfied) is enough.',
                'Guten Tag {customer_name}, wie zufrieden sind Sie mit unserer Leistung? Ein Klick auf eine Bewertung von 1 (sehr unzufrieden) bis 5 (sehr zufrieden) genügt.',
                'Bonjour {customer_name}, dans quelle mesure êtes-vous satisfait de notre prestation ? Un clic sur une note de 1 (très insatisfait) à 5 (très satisfait) suffit.',
            ],
            'ces' => [
                'Gentile {customer_name}, quanto è stato facile risolvere la sua esigenza con noi? Basta un clic su un punteggio da 1 (molto facile) a 7 (molto difficile).',
                'Dear {customer_name}, how easy was it to get your need solved with us? One click on a score from 1 (very easy) to 7 (very difficult) is enough.',
                'Guten Tag {customer_name}, wie einfach war es, Ihr Anliegen mit uns zu lösen? Ein Klick auf eine Bewertung von 1 (sehr einfach) bis 7 (sehr schwierig) genügt.',
                'Bonjour {customer_name}, dans quelle mesure a-t-il été facile de résoudre votre besoin avec nous ? Un clic sur une note de 1 (très facile) à 7 (très difficile) suffit.',
            ],
        ][$type];

        $label = ['Risponda con un solo clic:', 'Answer with one click:', 'Mit einem Klick antworten:', 'Répondre en un clic :'];

        return self::locales(static fn (int $i): array => ['subject' => $subject[$i], 'body' => $body[$i], 'one_click_label' => $label[$i]]);
    }

    /**
     * @return array<string, array<string, string>>
     */
    private static function followUps(string $type): array
    {
        $prompts = [
            'low' => [
                'nps' => ['Ci dispiace. Cosa è andato storto e cosa possiamo fare per rimediare?', 'We are sorry. What went wrong and what can we do to make it right?', 'Das tut uns leid. Was ist schiefgelaufen und was können wir tun, um es zu beheben?', 'Nous sommes désolés. Qu\'est-ce qui n\'a pas fonctionné et que pouvons-nous faire pour y remédier ?'],
                'csat' => ['Ci dispiace. Cosa non l\'ha soddisfatta?', 'We are sorry. What did not meet your expectations?', 'Das tut uns leid. Was hat Sie nicht zufriedengestellt?', 'Nous sommes désolés. Qu\'est-ce qui ne vous a pas satisfait ?'],
                'ces' => ['Ci dispiace. Cosa ha reso difficile la procedura?', 'We are sorry. What made it hard?', 'Das tut uns leid. Was hat es schwierig gemacht?', 'Nous sommes désolés. Qu\'est-ce qui a rendu la démarche difficile ?'],
            ],
            'mid' => [
                'nps' => ['Grazie. Cosa potremmo migliorare per meritare un punteggio più alto?', 'Thank you. What could we improve to earn a higher score?', 'Danke. Was könnten wir verbessern, um eine höhere Bewertung zu verdienen?', 'Merci. Que pourrions-nous améliorer pour mériter une meilleure note ?'],
                'csat' => ['Grazie. Cosa potremmo migliorare?', 'Thank you. What could we improve?', 'Danke. Was könnten wir verbessern?', 'Merci. Que pourrions-nous améliorer ?'],
                'ces' => ['Grazie. Cosa potremmo semplificare?', 'Thank you. What could we make simpler?', 'Danke. Was könnten wir vereinfachen?', 'Merci. Que pourrions-nous simplifier ?'],
            ],
            'high' => [
                'nps' => ['Grazie! Cosa le è piaciuto di più della nostra collaborazione?', 'Thank you! What did you like most about working with us?', 'Danke! Was hat Ihnen an der Zusammenarbeit am meisten gefallen?', 'Merci ! Qu\'avez-vous le plus apprécié dans notre collaboration ?'],
                'csat' => ['Grazie! Cosa ha reso l\'esperienza così positiva?', 'Thank you! What made the experience so good?', 'Danke! Was hat die Erfahrung so positiv gemacht?', 'Merci ! Qu\'est-ce qui a rendu l\'expérience si positive ?'],
                'ces' => ['Grazie! Cosa ha reso tutto così semplice?', 'Thank you! What made it so easy?', 'Danke! Was hat es so einfach gemacht?', 'Merci ! Qu\'est-ce qui a rendu cela si simple ?'],
            ],
        ];

        $config = [];
        foreach ($prompts as $band => $byType) {
            $config[$band] = self::locales(static fn (int $i): string => $byType[$type][$i]);
        }

        return $config;
    }

    /**
     * Expands a builder over [it, en, de, fr] into the five survey locales.
     *
     * @template T
     *
     * @param  callable(int): T  $build
     * @return array<string, T>
     */
    private static function locales(callable $build): array
    {
        return [
            'it-CH' => $build(0),
            'it-IT' => $build(0),
            'en' => $build(1),
            'de-CH' => $build(2),
            'fr-CH' => $build(3),
        ];
    }
}
