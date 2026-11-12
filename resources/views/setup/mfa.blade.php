<x-setup.page :livewire="$this">
    <form wire:submit="callMountedAction">
        {{-- Give password managers an explicit username instead of mistaking the last OTP digit for it. --}}
        <input class="setup-autofill-username" type="text" name="username" autocomplete="username" value="{{ filament()->auth()->user()->email }}" readonly tabindex="-1" aria-hidden="true">
        {{ $this->getSetupSchema() }}
    </form>
</x-setup.page>
