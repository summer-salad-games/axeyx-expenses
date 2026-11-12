<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Resources\Categories\Pages\CreateCategory;
use App\Filament\Resources\Categories\Pages\EditCategory;
use App\Filament\Resources\Categories\Pages\ListCategories;
use App\Models\Category;
use BackedEnum;
use Filament\Actions\EditAction;
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
                ->helperText('Désactivez une catégorie pour empêcher son utilisation dans les nouvelles dépenses. Son historique est conservé.'),
        ])->columns(1);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label('Nom')->searchable()->sortable(),
            IconColumn::make('is_active')->label('Active')->boolean(),
        ])->filters([TernaryFilter::make('is_active')->label('Statut')->searchable()->native(false)
            ->placeholder('Toutes')->trueLabel('Actives')->falseLabel('Désactivées')])
            ->recordActions([EditAction::make()->action(null)])->stackedOnMobile()->defaultSort('name')
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
}
