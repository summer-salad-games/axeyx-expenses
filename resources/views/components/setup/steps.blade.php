@props(['current', 'completed' => []])
<nav aria-label="Étapes de configuration" class="setup-steps">
    <ol>
        @foreach ([1 => ['icon' => 'heroicon-o-shield-check', 'label' => 'Authentification'], 2 => ['icon' => 'heroicon-o-key', 'label' => 'Mot de passe'], 3 => ['icon' => 'heroicon-o-rocket-launch', 'label' => 'Tout est prêt']] as $number => $step)
            <li @class(['setup-step', 'is-current' => $number === $current, 'is-done' => $number !== $current && ($completed[$number] ?? false)]) @if ($number === $current) aria-current="step" @endif>
                <span class="setup-step-icon"><x-filament::icon :icon="$step['icon']" /></span>
                <span>{{ $step['label'] }}</span>
            </li>
        @endforeach
    </ol>
</nav>
