<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\ContactCategory;
use App\Models\ContactCategoryTranslation;
use App\Models\ContactType;
use App\Models\ContactTypeTranslation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ContactLookupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $types = [
            'CLIENT' => [
                'it' => 'Cliente',
                'en' => 'Customer / Client',
                'de' => 'Kunde',
                'fr' => 'Client',
            ],
            'SUPPLIER' => [
                'it' => 'Fornitore',
                'en' => 'Vendor / Supplier',
                'de' => 'Lieferant',
                'fr' => 'Fournisseur',
            ],
            'CARRIER' => [
                'it' => 'Vettore / Spedizioniere',
                'en' => 'Carrier / Freight Forwarder',
                'de' => 'Spediteur / Frachtführer',
                'fr' => 'Transporteur / Transitaire',
            ],
            'PROSPECT' => [
                'it' => 'Potenziale Cliente (Lead)',
                'en' => 'Prospect / Lead',
                'de' => 'Interessent / Lead',
                'fr' => 'Prospect / Piste',
            ],
            'PARTNER' => [
                'it' => 'Partner Commerciale',
                'en' => 'Business Partner',
                'de' => 'Geschäftspartner',
                'fr' => 'Partenaire commercial',
            ],
        ];

        $categories = [
            'ENTERPRISE' => [
                'it' => 'Grandi Imprese / Multinazionali',
                'en' => 'Enterprise / Corporate',
                'de' => 'Großunternehmen / Konzern',
                'fr' => 'Grand compte / Entreprise',
            ],
            'SME' => [
                'it' => 'Piccole e Medie Imprese (PMI)',
                'en' => 'Small & Medium Enterprises (SME)',
                'de' => 'Kleine und mittlere Unternehmen (KMU)',
                'fr' => 'Petites et moyennes entreprises (PME)',
            ],
            'ARTISAN' => [
                'it' => 'Artigiani e Ditte Individuali',
                'en' => 'Craftsmen & Sole Proprietorships',
                'de' => 'Handwerker & Einzelunternehmen',
                'fr' => 'Artisans & Entreprises individuelles',
            ],
            'PROFESSIONAL' => [
                'it' => 'Liberi Professionisti e Consulenti',
                'en' => 'Consultants & Freelancers',
                'de' => 'Freiberufler & Berater',
                'fr' => 'Professions libérales & Consultants',
            ],
            'PUBLIC_ADMIN' => [
                'it' => 'Pubblica Amministrazione (PA)',
                'en' => 'Public Administration & Government',
                'de' => 'Öffentliche Verwaltung / Behörden',
                'fr' => 'Administration publique (Secteur public)',
            ],
        ];

        DB::transaction(function () use ($types, $categories): void {
            foreach ($types as $code => $translations) {
                /** @var ContactType $type */
                $type = ContactType::updateOrCreate(
                    ['code' => $code],
                    ['is_active' => true]
                );

                foreach ($translations as $locale => $name) {
                    ContactTypeTranslation::updateOrCreate(
                        [
                            'contact_type_id' => $type->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $name,
                        ]
                    );
                }
            }

            foreach ($categories as $code => $translations) {
                /** @var ContactCategory $category */
                $category = ContactCategory::updateOrCreate(
                    ['code' => $code],
                    ['is_active' => true]
                );

                foreach ($translations as $locale => $name) {
                    ContactCategoryTranslation::updateOrCreate(
                        [
                            'contact_category_id' => $category->id,
                            'locale' => $locale,
                        ],
                        [
                            'name' => $name,
                        ]
                    );
                }
            }
        });
    }
}
