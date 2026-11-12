@props(['livewire'])

<div class="setup-flow">
    <header class="setup-header">
        @include('components.brand')
        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <button type="submit" class="setup-signout">Se déconnecter</button>
        </form>
    </header>
    <main id="fi-main-content" class="setup-main">
        <x-setup.steps :current="$livewire->getSetupStep()" :completed="[
            1 => filled(filament()->auth()->user()->getAppAuthenticationSecret()),
            2 => ! filament()->auth()->user()->must_change_password,
        ]" />
        <section class="setup-card" aria-labelledby="setup-heading">
            <div class="setup-card-heading">
                <span class="setup-card-icon">
                    <x-filament::icon :icon="[1 => 'heroicon-o-shield-check', 2 => 'heroicon-o-key', 3 => 'heroicon-o-rocket-launch'][$livewire->getSetupStep()]" />
                </span>
                <div>
                    <h1 id="setup-heading">{{ $livewire->getHeading() }}</h1>
                    <p>{{ $livewire->getSubheading() }}</p>
                </div>
            </div>
            {{ $slot }}
        </section>
    </main>
</div>
