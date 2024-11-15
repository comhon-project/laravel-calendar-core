<?php

namespace Comhon\Calendar;

use Comhon\Calendar\Models\Event;
use Illuminate\Support\Facades\Gate;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class CalendarServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        /*
         * This class is a Package Service Provider
         *
         * More info: https://github.com/spatie/laravel-package-tools
         */
        $package
            ->name('laravel-calendar-core')
            ->hasConfigFile()
            ->hasMigration('create_calendar_core_table')
            ->hasRoute('routes');
    }

    public function bootingPackage()
    {
        if (! config('calendar-core.api.active')) {
            $this->package->routeFileNames = [];
        }
    }

    public function packageBooted()
    {
        $this->registerPolicies();
        $this->publishFiles();
    }

    public function registerPolicies()
    {
        if (config('calendar-core.use_policies')) {
            $policies = Gate::policies();
            if (! isset($policies[Event::class])) {
                Gate::policy(Event::class, 'App\Policies\Calendar\EventPolicy');
            }
        }
    }

    public function publishFiles()
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                dirname(__DIR__).DIRECTORY_SEPARATOR.'policies' => app_path('Policies'.DIRECTORY_SEPARATOR.'Calendar'),
            ], 'calendar-core-policies');
        }
    }
}
