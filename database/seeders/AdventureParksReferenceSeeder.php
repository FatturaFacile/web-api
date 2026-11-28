<?php

declare(strict_types=1);

namespace Database\Seeders;

use Modules\AdventureParks\Models\EducationalWorkshop;
use Modules\AdventureParks\Models\WaiverTemplate;

/**
 * Global reference data of the adventure parks (Mod 299), in the four languages (it, en, de, fr): the legal text of the liability
 * waiver (served by the public waiver endpoint, never by a frontend dictionary) and the standard catalogue of the educational farm
 * workshops with their names and descriptions. A company can publish its own waiver version or override a workshop with its own row.
 *
 * Idempotent: running it again updates the rows by their natural key. The waiver text is a standard template: the operator of the
 * park must have it reviewed by its own legal adviser before use.
 */
final class AdventureParksReferenceSeeder extends GlobalDataSeeder
{
    protected function seedGlobalData(): void
    {
        $this->waiverTemplates();
        $this->workshops();
    }

    private function waiverTemplates(): void
    {
        $version = (string) config('adventure_parks.waiver_version_id', 'v1');

        foreach ($this->waiverTexts() as $locale => $text) {
            WaiverTemplate::query()->updateOrCreate(
                ['tenant_id' => null, 'version_id' => $version, 'locale' => $locale],
                ['title' => $text['title'], 'paragraphs' => $text['paragraphs'], 'rules' => $text['rules']],
            );
        }
    }

    private function workshops(): void
    {
        foreach ((array) config('adventure_parks.default_workshops') as $code => $defaults) {
            $texts = $this->workshopTexts()[$code];

            EducationalWorkshop::query()->updateOrCreate(
                ['tenant_id' => null, 'code' => $code],
                [
                    'duration_minutes' => $defaults['duration_minutes'],
                    'max_participants' => $defaults['max_participants'],
                    'price_per_student' => $defaults['price_per_student'],
                    'name_translations' => array_map(static fn (array $row): string => $row['name'], $texts),
                    'description_translations' => array_map(static fn (array $row): string => $row['description'], $texts),
                    'is_active' => true,
                ],
            );
        }
    }

    /**
     * @return array<string, array{title: string, paragraphs: list<string>, rules: list<string>}>
     */
    private function waiverTexts(): array
    {
        return [
            'it' => [
                'title' => 'Dichiarazione di assunzione del rischio e manleva',
                'paragraphs' => [
                    'Il sottoscritto dichiara di aver compreso che l\'attività sui percorsi sospesi (parco avventura, arrampicata) comporta rischi connaturati allo sport, tra cui cadute, urti e distorsioni, anche in presenza di dispositivi di protezione individuale conformi alle norme UNI EN 15567 ed EN 365.',
                    'Il sottoscritto dichiara di essere in buone condizioni fisiche, di non essere sotto l\'effetto di alcol o sostanze, di non avere patologie cardiache, epilettiche o altre condizioni che sconsiglino l\'attività, e di non essere in stato di gravidanza.',
                    'Il sottoscritto dichiara di firmare anche in nome e per conto dei minori indicati, dei quali è genitore, tutore o accompagnatore autorizzato, e di vigilare su di essi per tutta la durata dell\'attività.',
                    'Il sottoscritto solleva il gestore da ogni responsabilità per i danni derivanti dal mancato rispetto delle istruzioni e del regolamento. Resta ferma la responsabilità del gestore per dolo o colpa grave e in ogni altro caso in cui la legge non consenta di escluderla.',
                    'I dati personali raccolti (anagrafica, firma, indirizzo IP e data della firma) sono trattati per la gestione della prenotazione e come prova dell\'accettazione, ai sensi del Regolamento (UE) 2016/679.',
                ],
                'rules' => [
                    'Ascolto attentamente il briefing di sicurezza e seguo le istruzioni degli istruttori.',
                    'Indosso l\'imbracatura e il casco forniti e non li modifico né li tolgo durante il percorso.',
                    'Aggancio sempre entrambi i moschettoni prima di passare da una sezione all\'altra.',
                    'Non supero la portata massima di persone indicata su ogni piattaforma e su ogni passaggio.',
                    'Rispetto le distanze tra i partecipanti e non corro né salto sui percorsi.',
                    'Non porto con me oggetti sciolti e raccolgo i capelli lunghi.',
                    'Segnalo subito all\'istruttore qualsiasi malessere o difficoltà.',
                    'I minori restano sempre sotto la sorveglianza dell\'adulto firmatario.',
                ],
            ],
            'en' => [
                'title' => 'Assumption of risk and liability waiver',
                'paragraphs' => [
                    'The undersigned acknowledges that activity on the suspended courses (adventure park, climbing) involves risks inherent to the sport, including falls, impacts and sprains, even when personal protective equipment complies with UNI EN 15567 and EN 365.',
                    'The undersigned declares to be in good physical condition, not under the influence of alcohol or drugs, free of heart conditions, epilepsy or other conditions that make the activity inadvisable, and not pregnant.',
                    'The undersigned also signs in the name and on behalf of the minors listed, for whom they are the parent, guardian or authorised companion, and will supervise them throughout the activity.',
                    'The undersigned releases the operator from liability for damage resulting from failure to follow the instructions and the rules. The operator remains liable for wilful misconduct and gross negligence and wherever the law does not allow liability to be excluded.',
                    'The personal data collected (identity, signature, IP address and date of signing) are processed to manage the booking and as proof of acceptance, in accordance with Regulation (EU) 2016/679.',
                ],
                'rules' => [
                    'I listen carefully to the safety briefing and follow the instructors\' instructions.',
                    'I wear the harness and helmet provided and do not modify or remove them during the course.',
                    'I always clip both carabiners before moving from one section to the next.',
                    'I do not exceed the maximum number of people shown on each platform and crossing.',
                    'I keep my distance from other participants and do not run or jump on the courses.',
                    'I carry no loose objects and tie back long hair.',
                    'I tell the instructor immediately about any discomfort or difficulty.',
                    'Minors always remain under the supervision of the signing adult.',
                ],
            ],
            'de' => [
                'title' => 'Risikoübernahme und Haftungsverzicht',
                'paragraphs' => [
                    'Der Unterzeichnende anerkennt, dass die Aktivität auf den hängenden Parcours (Hochseilgarten, Klettern) sportartbedingte Risiken wie Stürze, Stösse und Verstauchungen mit sich bringt, auch wenn die persönliche Schutzausrüstung den Normen UNI EN 15567 und EN 365 entspricht.',
                    'Der Unterzeichnende erklärt, bei guter körperlicher Verfassung zu sein, nicht unter Alkohol- oder Drogeneinfluss zu stehen, keine Herz- oder Epilepsieerkrankung oder andere Beschwerden zu haben, die von der Aktivität abraten lassen, und nicht schwanger zu sein.',
                    'Der Unterzeichnende unterschreibt auch im Namen und für Rechnung der aufgeführten Minderjährigen, für die er Elternteil, Vormund oder bevollmächtigte Begleitperson ist, und beaufsichtigt sie während der gesamten Aktivität.',
                    'Der Unterzeichnende entbindet den Betreiber von der Haftung für Schäden, die durch Nichtbeachtung der Anweisungen und der Regeln entstehen. Die Haftung des Betreibers für Vorsatz und grobe Fahrlässigkeit sowie in allen Fällen, in denen das Gesetz einen Haftungsausschluss nicht erlaubt, bleibt bestehen.',
                    'Die erhobenen personenbezogenen Daten (Identität, Unterschrift, IP-Adresse und Datum der Unterschrift) werden zur Verwaltung der Buchung und als Nachweis der Zustimmung gemäss der Verordnung (EU) 2016/679 und dem schweizerischen Datenschutzgesetz verarbeitet.',
                ],
                'rules' => [
                    'Ich höre die Sicherheitseinweisung aufmerksam an und befolge die Anweisungen der Instruktoren.',
                    'Ich trage den ausgegebenen Gurt und Helm und verändere oder entferne sie während des Parcours nicht.',
                    'Ich hänge immer beide Karabiner ein, bevor ich zum nächsten Abschnitt wechsle.',
                    'Ich überschreite nicht die auf jeder Plattform und jedem Übergang angegebene Höchstzahl an Personen.',
                    'Ich halte Abstand zu den anderen Teilnehmern und renne oder springe nicht auf dem Parcours.',
                    'Ich führe keine losen Gegenstände mit und binde lange Haare zusammen.',
                    'Ich melde dem Instruktor sofort jedes Unwohlsein oder jede Schwierigkeit.',
                    'Minderjährige bleiben stets unter der Aufsicht des unterzeichnenden Erwachsenen.',
                ],
            ],
            'fr' => [
                'title' => 'Acceptation du risque et décharge de responsabilité',
                'paragraphs' => [
                    'Le soussigné reconnaît que l\'activité sur les parcours suspendus (parc aventure, escalade) comporte des risques inhérents au sport, notamment des chutes, des chocs et des entorses, même lorsque les équipements de protection individuelle sont conformes aux normes UNI EN 15567 et EN 365.',
                    'Le soussigné déclare être en bonne condition physique, ne pas être sous l\'influence d\'alcool ou de substances, ne pas souffrir d\'une maladie cardiaque, d\'épilepsie ou d\'une autre affection qui déconseille l\'activité, et ne pas être enceinte.',
                    'Le soussigné signe également au nom et pour le compte des mineurs indiqués, dont il est le parent, le tuteur ou l\'accompagnateur autorisé, et les surveille pendant toute la durée de l\'activité.',
                    'Le soussigné décharge l\'exploitant de toute responsabilité pour les dommages résultant du non-respect des consignes et du règlement. La responsabilité de l\'exploitant demeure en cas de dol ou de faute grave et dans tous les cas où la loi ne permet pas de l\'exclure.',
                    'Les données personnelles collectées (identité, signature, adresse IP et date de signature) sont traitées pour la gestion de la réservation et comme preuve de l\'acceptation, conformément au Règlement (UE) 2016/679.',
                ],
                'rules' => [
                    'J\'écoute attentivement le briefing de sécurité et je suis les consignes des moniteurs.',
                    'Je porte le harnais et le casque fournis et je ne les modifie ni ne les retire pendant le parcours.',
                    'Je fixe toujours les deux mousquetons avant de passer d\'une section à l\'autre.',
                    'Je ne dépasse pas le nombre maximal de personnes indiqué sur chaque plateforme et chaque passage.',
                    'Je respecte les distances avec les autres participants et je ne cours ni ne saute sur les parcours.',
                    'Je ne porte aucun objet non fixé et j\'attache mes cheveux longs.',
                    'Je signale immédiatement au moniteur tout malaise ou toute difficulté.',
                    'Les mineurs restent toujours sous la surveillance de l\'adulte signataire.',
                ],
            ],
        ];
    }

    /**
     * @return array<string, array<string, array{name: string, description: string}>>
     */
    private function workshopTexts(): array
    {
        return [
            'BREAD_GRAIN' => [
                'it' => ['name' => 'Dal grano al pane', 'description' => 'I bambini macinano il grano, impastano e cuociono il proprio pane nel forno a legna.'],
                'en' => ['name' => 'From grain to bread', 'description' => 'Children grind the grain, knead and bake their own bread in the wood-fired oven.'],
                'de' => ['name' => 'Vom Korn zum Brot', 'description' => 'Die Kinder mahlen das Korn, kneten den Teig und backen ihr eigenes Brot im Holzofen.'],
                'fr' => ['name' => 'Du grain au pain', 'description' => 'Les enfants moulent le grain, pétrissent la pâte et cuisent leur propre pain dans le four à bois.'],
            ],
            'BEES' => [
                'it' => ['name' => 'Il mondo delle api', 'description' => 'Alla scoperta dell\'alveare e del lavoro delle api, con assaggio di miele e tuta protettiva.'],
                'en' => ['name' => 'The world of bees', 'description' => 'Discovering the hive and the work of bees, with honey tasting and protective suits.'],
                'de' => ['name' => 'Die Welt der Bienen', 'description' => 'Entdeckung des Bienenstocks und der Arbeit der Bienen, mit Honigverkostung und Schutzanzug.'],
                'fr' => ['name' => 'Le monde des abeilles', 'description' => 'À la découverte de la ruche et du travail des abeilles, avec dégustation de miel et combinaison de protection.'],
            ],
            'GARDEN' => [
                'it' => ['name' => 'Orto didattico', 'description' => 'Semina, cura e raccolta degli ortaggi di stagione nell\'orto della fattoria.'],
                'en' => ['name' => 'Educational vegetable garden', 'description' => 'Sowing, tending and harvesting seasonal vegetables in the farm garden.'],
                'de' => ['name' => 'Lerngarten', 'description' => 'Säen, pflegen und ernten von saisonalem Gemüse im Garten des Bauernhofs.'],
                'fr' => ['name' => 'Potager pédagogique', 'description' => 'Semis, entretien et récolte des légumes de saison dans le potager de la ferme.'],
            ],
            'JUNIOR_ADVENTURE' => [
                'it' => ['name' => 'Percorso avventura junior', 'description' => 'Percorso sospeso a bassa quota pensato per i più piccoli, con imbracatura e istruttore.'],
                'en' => ['name' => 'Junior adventure course', 'description' => 'Low-level suspended course designed for the youngest, with harness and instructor.'],
                'de' => ['name' => 'Junior-Abenteuerparcours', 'description' => 'Niedriger Hängeparcours für die Kleinsten, mit Gurt und Instruktor.'],
                'fr' => ['name' => 'Parcours aventure junior', 'description' => 'Parcours suspendu à faible hauteur conçu pour les plus petits, avec harnais et moniteur.'],
            ],
        ];
    }
}
