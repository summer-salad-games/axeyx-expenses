<?php

namespace App\Filament\Resources\Expenses\RelationManagers;

use App\Models\Attachment;
use App\Services\AttachmentService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class AttachmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'attachments';

    protected static ?string $title = 'Pièces jointes';

    protected static ?bool $isLazyLoaded = false;

    public function isReadOnly(): bool
    {
        return false; // Individual policies still authorize each confirmed removal on detail pages.
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Gate::allows('view', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->columns([
            ImageColumn::make('preview')->label('Aperçu')->state(fn (Attachment $record) => $record->mime_type === 'application/pdf' ? null : route('attachments.show', ['attachment' => $record, 'mode' => 'view']))
                ->width(64)->height(64)->placeholder('PDF')
                ->url(fn (Attachment $record) => $record->mime_type === 'application/pdf' ? null : route('attachments.show', ['attachment' => $record, 'mode' => 'view']))
                ->openUrlInNewTab(),
            TextColumn::make('original_name')->label('Document')->wrap()->searchable(),
            TextColumn::make('size_bytes')->label('Taille')->formatStateUsing(fn (int $state) => $state < 1024 ? $state.' octets' : number_format($state / 1024, 1, ',', ' ').' Kio'),
        ])->recordActions([
            Action::make('download')->label('Télécharger')->icon('heroicon-o-arrow-down-tray')
                ->authorize(fn (Attachment $record) => Gate::allows('view', $record))
                ->url(fn (Attachment $record) => route('attachments.show', ['attachment' => $record])),
            Action::make('view')->label('Ouvrir')->icon('heroicon-o-arrow-top-right-on-square')
                ->authorize(fn (Attachment $record) => Gate::allows('view', $record))
                ->url(fn (Attachment $record) => route('attachments.show', ['attachment' => $record, 'mode' => 'view']))->openUrlInNewTab(),
            DeleteAction::make()->label('Retirer')->modalHeading('Supprimer définitivement cette pièce jointe ?')
                ->modalDescription('Le document sera supprimé définitivement. La dépense sera conservée.')
                ->authorize(fn (Attachment $record) => Gate::allows('delete', $record))
                ->using(fn (Attachment $record): bool => app(AttachmentService::class)->remove(Filament::auth()->user(), $record)),
        ])->stackedOnMobile()->paginated(false)->emptyStateHeading('Aucune pièce jointe')
            ->emptyStateDescription('Ajoutez des documents depuis le formulaire de modification de la dépense.');
    }
}
