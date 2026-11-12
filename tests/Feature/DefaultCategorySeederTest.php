<?php

namespace Tests\Feature;

use App\Enums\AuditActorKind;
use App\Models\AuditLog;
use App\Models\Category;
use Database\Seeders\DefaultCategorySeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Tests\TestCase;

class DefaultCategorySeederTest extends TestCase
{
    use DatabaseMigrations;

    public function test_default_seeding_adds_medical_and_operating_categories_with_audits_but_no_accounts(): void
    {
        $this->seed();
        $this->assertDatabaseCount('categories', 20);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('expenses', 0);
        foreach (['Équipements médicaux', 'Maintenance et étalonnage', 'Médicaments, réactifs et gaz médicaux', 'Informatique, logiciels et visioconférence', 'Loyer et charges locatives', 'Autres dépenses'] as $name) {
            $this->assertDatabaseHas('categories', ['name' => $name, 'name_key' => Category::nameKey($name), 'is_active' => true]);
        }
        $this->assertSame(20, AuditLog::query()->where('action', 'category.created')
            ->where('subject_type', Category::class)->where('actor_kind', AuditActorKind::Operator)->whereNull('actor_id')->count());
    }

    public function test_reseeding_preserves_existing_names_disabled_status_and_custom_categories(): void
    {
        $existing = Category::create(['name' => 'ÉQUIPEMENTS MÉDICAUX']);
        $existing->forceFill(['is_active' => false])->save();
        $custom = Category::create(['name' => 'Catégorie personnalisée']);
        $before = $existing->fresh()->getRawOriginal();
        $this->seed(DefaultCategorySeeder::class);
        $this->seed(DefaultCategorySeeder::class);
        $this->assertDatabaseCount('categories', 21);
        $this->assertDatabaseCount('audit_logs', 19);
        $this->assertSame($before, $existing->fresh()->getRawOriginal());
        $this->assertDatabaseHas('categories', ['id' => $custom->id, 'name' => 'Catégorie personnalisée']);
    }
}
