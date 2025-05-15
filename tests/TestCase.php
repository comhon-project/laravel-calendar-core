<?php

namespace Tests;

use App\Models\User;
use App\Providers\WorkbenchServiceProvider;
use Comhon\Calendar\CalendarServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => str_contains($modelName, 'Calendar\\')
                ? 'Comhon\\Calendar\\Database\\Factories\\'.class_basename($modelName).'Factory'
                : 'Database\\Factories\\'.class_basename($modelName).'Factory'
        );
        Factory::guessModelNamesUsing(
            fn ($factory) => str_contains(get_class($factory), 'Calendar\\')
             ? 'Comhon\\Calendar\\Models\\'.str_replace('Factory', '', class_basename(get_class($factory)))
             : 'App\\Models\\'.str_replace('Factory', '', class_basename(get_class($factory)))
        );
    }

    protected function getPackageProviders($app)
    {
        return [
            WorkbenchServiceProvider::class,
            CalendarServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Warning! set a specific config for only one test
        $useRoutes = $this->name() != 'test_list_events_dont_use_route';

        config()->set('calendar-core.participant_model', User::class);
        config()->set('calendar-core.creator_model', User::class);
        config()->set('calendar-core.api.active', $useRoutes);
        config()->set('calendar-core.api.middleware', ['api', 'auth']);
        config()->set('calendar-core.api.prefix', 'api');
        config()->set('calendar-core.use_policies', true);

        if (! Schema::hasTable('calendar_events')) {
            $migration = include __DIR__.'/../workbench/database/Migrations/create_test_table.php';
            $migration->up();
            $migration = include __DIR__.'/../database/migrations/create_calendar_core_table.php.stub';
            $migration->up();
        }

        $this->setPoliciesFiles();
    }

    public function setPoliciesFiles()
    {
        $stubPolicyDir = __DIR__.'/../policies';
        $appPolicyDir = __DIR__.'/../workbench/app/Policies/Calendar';

        if (file_exists($appPolicyDir)) {
            $files = array_diff(scandir($appPolicyDir), ['.', '..']);
            foreach ($files as $file) {
                unlink($appPolicyDir.'/'.$file);
            }
            rmdir($appPolicyDir);
        }
        mkdir($appPolicyDir, 0775, true);

        $files = array_diff(scandir($stubPolicyDir), ['.', '..']);
        foreach ($files as $file) {
            $policy = str_replace(
                ['// TODO put your authorization logic here', 'App\Models\User'],
                ['return $user->has_consumer_ability == true;', 'App\Models\User'],
                file_get_contents($stubPolicyDir.'/'.$file),
            );
            file_put_contents($appPolicyDir.'/'.$file, $policy);
        }
    }

    public static function providerBoolean()
    {
        return [
            [false],
            [true],
        ];
    }
}
