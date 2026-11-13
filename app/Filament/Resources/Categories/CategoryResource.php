<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use App\Services\CategoryService;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CategoryResource extends Resource
{
    protected static ?string $model = Category::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?string $modelLabel = 'catégorie';

    protected static ?string $pluralModelLabel = 'Catégories';

    protected static ?string $recordTitleAttribute = 'name';

    protected static ?int $navigationSort = 20;

    protected static bool $isGloballySearchable = false;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nom')->required()->maxLength(100),
            Toggle::make('is_active')->label('Catégorie active')->default(true)
                ->helperText('Une catégorie utilisée peut être désactivée pour empêcher de nouvelles sélections. Seules les catégories sans dépense peuvent être supprimées.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nom')->searchable()->sortable(),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->filters([TernaryFilter::make('is_active')->label('Statut')->searchable()->native(false)
            ->placeholder('Toutes')->trueLabel('Actives')->falseLabel('Désactivées')])
            ->recordActions([EditAction::make()->action(null), self::deleteAction()])->stackedOnMobile()->defaultSort('name')
            ->emptyStateHeading('Aucune catégorie')->emptyStateDescription('Créez une catégorie pour commencer à enregistrer des dépenses.');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCategories::route('/'),
            'create' => CreateCategory::route('/create'),
            'edit' => EditCategory::route('/{record}/edit'),
        ];
    }

    public static function deleteAction(): DeleteAction
    {
        return DeleteAction::make()->authorize(fn (Category $record) => self::canDelete($record))
            ->modalHeading('Supprimer définitivement cette catégorie ?')
            ->modalDescription('Cette catégorie n’est utilisée par aucune dépense. Cette action est irréversible.')
            ->using(fn (Category $record): bool => app(CategoryService::class)->delete(Filament::auth()->user(), $record));
    }
}
