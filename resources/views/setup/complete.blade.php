<x-setup.page :livewire="$this">
    <ul class="setup-checklist">
        <li><x-filament::icon icon="heroicon-o-check-circle" /> Authentification à deux facteurs activée</li>
        <li><x-filament::icon icon="heroicon-o-check-circle" /> Mot de passe personnel enregistré</li>
    </ul>
    <x-filament::button wire:click="finish" wire:loading.attr="disabled" size="lg">Accéder à l’application</x-filament::button>
</x-setup.page>
