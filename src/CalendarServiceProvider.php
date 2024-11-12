<?php

namespace ComhonProject\Calendar;

use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;
use ComhonProject\Calendar\Commands\CalendarCommand;

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
            ->hasMigration('create_laravel_calendar_core_table');
    }
}
