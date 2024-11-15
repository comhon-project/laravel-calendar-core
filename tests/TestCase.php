<?php

namespace Tests;

use Comhon\Calendar\CalendarServiceProvider;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Relations\Relation;
use Orchestra\Testbench\TestCase as Orchestra;
use Tests\Models\TrainingProgram;
use Tests\Models\TrainingSession;
use Tests\Models\User;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        Factory::guessFactoryNamesUsing(
            fn (string $modelName) => str_contains($modelName, 'Tests\\')
                ? 'Tests\\Database\\Factories\\'.class_basename($modelName).'Factory'
                : 'Comhon\\Calendar\\Database\\Factories\\'.class_basename($modelName).'Factory'
        );
        Factory::guessModelNamesUsing(
            fn ($factory) => str_contains(get_class($factory), 'Tests\\')
             ? 'Tests\\Models\\'.str_replace('Factory', '', class_basename(get_class($factory)))
             : 'Comhon\\Calendar\\Models\\'.str_replace('Factory', '', class_basename(get_class($factory)))
        );
        Relation::enforceMorphMap([
            'user' => User::class,
            'program' => TrainingProgram::class,
            'session' => TrainingSession::class,
        ]);
    }

    protected function getPackageProviders($app)
    {
        return [
            CalendarServiceProvider::class,
        ];
    }

    public function getEnvironmentSetUp($app)
    {
        // Warning! set a specific config for only one test
        $useRoutes = $this->name() != 'testListEventsDontUseRoute';

        config()->set('calendar-core.participant_model', User::class);
        config()->set('calendar-core.creator_model', User::class);
        config()->set('calendar-core.api.active', $useRoutes);
        config()->set('calendar-core.api.middleware', ['api', 'auth']);
        config()->set('calendar-core.api.prefix', 'api');
        config()->set('calendar-core.use_policies', true);
        config()->set('database.default', 'testing');
        config()->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $migration = include __DIR__.'/../database/migrations/create_calendar_core_table.php.stub';
        $migration->up();

        $migration = include __DIR__.'/Database/Migrations/create_test_table.php';
        $migration->up();

        $this->setPoliciesFiles();
    }

    public function setPoliciesFiles()
    {
        $stubPolicyDir = __DIR__.'/../policies';
        $testPolicyDir = __DIR__.'/Policies/Calendar';

        if (file_exists($testPolicyDir)) {
            $files = array_diff(scandir($testPolicyDir), ['.', '..']);
            foreach ($files as $file) {
                unlink($testPolicyDir.'/'.$file);
            }
            rmdir($testPolicyDir);
        }
        mkdir($testPolicyDir, 0775, true);

        $files = array_diff(scandir($stubPolicyDir), ['.', '..']);
        foreach ($files as $file) {
            $policy = str_replace(
                ['// TODO put your authorization logic here', 'App\Models\User'],
                ['return $user->has_consumer_ability == true;', 'Tests\Models\User'],
                file_get_contents($stubPolicyDir.'/'.$file),
            );
            file_put_contents($testPolicyDir.'/'.$file, $policy);
        }
    }
}
