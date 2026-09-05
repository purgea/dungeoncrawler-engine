<?php

namespace App\Providers;

use App\Models\WorldStageLevel;
use Illuminate\Support\Facades\Artisan;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Settings;
use Native\Desktop\Facades\Window;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // Settings survive app upgrades, while the bundled database can be
        // replaced or cleared. Check the actual content so the title screen's
        // New Journey route never points at an empty campaign.
        if (! Settings::get('seeded', false) || ! WorldStageLevel::query()->exists()) {
            Artisan::call('db:seed');
            Settings::set('seeded', true);
        }

        Window::open()->maximized()
            ->webPreferences([
                'devTools' => true,
                'webSecurity' => false,
            ]);
    }

    /**
     * Return an array of php.ini directives to be set.
     */
    public function phpIni(): array
    {
        return [
        ];
    }
}
