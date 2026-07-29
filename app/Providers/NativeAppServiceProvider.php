<?php

namespace App\Providers;

use Illuminate\Support\Facades\Artisan;
use Native\Desktop\Contracts\ProvidesPhpIni;
use Native\Desktop\Facades\Window;
use Native\Desktop\Facades\Settings;

class NativeAppServiceProvider implements ProvidesPhpIni
{
    /**
     * Executed once the native application has been booted.
     * Use this method to open windows, register global shortcuts, etc.
     */
    public function boot(): void
    {
        // TODO: Remove these forget statements before release
        Settings::forget('seeded');
        Settings::forget('current_level_seed');
        
        if (! Settings::get('seeded', false)) {
            Artisan::call('db:seed');
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
