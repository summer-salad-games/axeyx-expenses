<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Category;
use App\Services\AuditRecorder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DefaultCategorySeeder extends Seeder
{
    private const NAMES = [
        'Équipements médicaux',
        'Maintenance et étalonnage',
        'Consommables médicaux',
        'Médicaments, réactifs et gaz médicaux',
        'Hygiène, nettoyage et déchets médicaux',
        'Salaires et charges sociales',
        'Honoraires et prestations externes',
        'Loyer et charges locatives',
        'Électricité, eau et gaz',
        'Téléphonie et Internet',
        'Informatique, logiciels et visioconférence',
        'Fournitures administratives',
        'Assurances',
        'Frais bancaires',
        'Transport et déplacements',
        'Formation et documentation médicale',
        'Communication et publicité',
        'Impôts et taxes',
        'Entretien et aménagement des locaux',
        'Autres dépenses',
    ];

    public function run(): void
    {
        foreach (['expense' => self::NAMES, 'revenue' => ['Examens médicaux', 'Consultations médicales', 'Autres recettes']] as $type => $names) {
            foreach ($names as $name) {
                DB::transaction(function () use ($name, $type): void {
                    if (Category::query()->where('type', $type)->where('name_key', Category::nameKey($name))->exists()) {
                        return;
                    }

                    $category = new Category;
                    $category->forceFill(['name' => $name, 'type' => $type])->save();
                    app(AuditRecorder::class)->record('category.created', $category);
                }, 3);
            }
        }
    }
}
