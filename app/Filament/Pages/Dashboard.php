<?php

namespace App\Filament\Pages;

use App\Models\User;
use App\Security\AccountAccess;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Components\Attributes\Unexposed;

final class Dashboard extends BaseDashboard
{
    #[Unexposed]
    public static function canAccess(): bool
    {
        $user = Filament::auth()->user();

        return $user instanceof User && app(AccountAccess::class)->allows($user);
    }
}
