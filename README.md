# Calendar Core

[![Latest Version on Packagist](https://img.shields.io/packagist/v/comhon-project/laravel-calendar-core.svg?style=flat-square)](https://packagist.org/packages/comhon-project/laravel-calendar-core)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/comhon-project/laravel-calendar-core/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/comhon-project/laravel-calendar-core/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/comhon-project/laravel-calendar-core/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/comhon-project/laravel-calendar-core/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/comhon-project/laravel-calendar-core.svg?style=flat-square)](https://packagist.org/packages/comhon-project/laravel-calendar-core)

Calendar Core is a laravel calendar library that faciliate events scheduling (only backend part).

## Table of Contents

-   [Installation](#installation)
-   [Explanation](#explanation)
-   [Models, Interfaces, and Objects](#models-interfaces-and-objects)
    -   [Event Model](#event-model)
    -   [Schedulable Model](#schedulable-model)
    -   [Schedulable Series Model](#schedulable-series-model)
    -   [Schedulable Serie Object](#schedulable-serie-object)
-   [Usage](#usage)
    -   [Event Service](#event-service)
    -   [Schedulable Service](#schedulable-service)
    -   [Schedulable Serie Service](#schedulable-serie-service)
    -   [Config](#config)
    -   [API](#api)
-   [Tips](#tips)
    -   [Schedulable Series models and events aggregations](#schedulable-series-models-and-events-aggregations)
    -   [Observers](#observers)
-   [Test](#test)

## Installation

You can install the package via composer:

```bash
composer require comhon-project/laravel-calendar-core
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="calendar-core-config"
```

Warning! Before running migration, make sure that `participant_model` and `creator_model` configurations are correct. Otherwise, the migration may define wrong foreign key constraints.

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="calendar-core-migrations"
php artisan migrate
```

Optionally, you can publish the policies using

```bash
php artisan vendor:publish --tag="calendar-core-policies"
```

## Explanation

The Calendar Core library permit to build caldendars for your users by adding events and scheduling them as they wish. An event is a generic model that can be associated to anything you want (as far as it is a eloquent model).

This way, generic calendar events and your models management are decoupled, for a more maintainable and easy to understand architecture.

## Models, interfaces and objects

### Event model

the `Event` model is an eloquent model that store typical informations for a calendar event :

-   `id`: the event unique id
-   `name`: the event name
-   `creator_id`: the user that have created the event
-   `start_at`: date time from which the event starts
-   `end_at`: date time at which the event ends
-   `duration`: duration in minutes between `end_at` and `start_at` (readonly, auto generated value)
-   `schedulable_id`: a schedulable model id (only if you have associated a model to the event)
-   `schedulable_type`: a schedulable model type (only if you have associated a model to the event)

An event has participants too. Each guest may accept or not to participate to event. You can retrieve participants through the relationship `participants`.

The `Event` model is the main model to refer to for calendar views and scheduling purposes. But it is often not enough to display/manage the scheduling in your project with only events, due to some specifics informations or behaviours. To satisfy this need, you can associate schedulable models from your project.

### Schedulable model

A Schedulable model is an eloquent model that is DIRECTLY associated to one or several events. A Schedulable model MUST inherit laravel `Model` and implements `SchedulableInterface`.

#### One to One

For example your application permit to manage training sessions, you might have a Model `TrainingSession` that store specifics informations. This model should be a Schedulable model that is associated to only one event.

In this case you should use the trait `SchedulableUniqueTrait` that has a `event` relationship to model `Event`.

```php
class TrainingSession extends Model implements SchedulableInterface
{
    use SchedulableUniqueTrait;

    public function getEventName(): string
    {
        return 'training session';
    }
}
```

#### One to Many

For example your application permit to manage training programs, you might have a Model `TrainingProgram` that store specifics informations. This model might be a Schedulable model that is associated to many events (each event being a training session but without associated specific model).

In this case you should use the trait `SchedulableTrait` that has a `events` relationship to model `Event`.

```php
class TrainingProgram extends Model implements SchedulableInterface
{
    use SchedulableTrait;

    public function getEventName(): string
    {
        return 'training session';
    }
}
```

### Schedulable Series model

A Schedulable Series model is an eloquent model that is INDIRECTLY associated to events through associated Schedulable models. For example your application permit to manage training programs, you might have a Model `TrainingProgram` and this model might have a relationship `sessions` (related to a model `TrainingSession` that implements `SchedulableInterface`). This model should be a Schedulable Series model. A Schedulable Series model may have several series as long as you have corresponding relationships.

A Schedulable Series model MUST inherit laravel `Model` and implements `SchedulableSeriesInterface`.

```php
class TrainingProgram extends Model implements SchedulableSeriesInterface
{
    public function series(): array
    {
        return ['sessions'];
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(TrainingSession::class);
    }
}
```

### Schedulable Serie object

The object `SchedulableSerie` is a wrapper that contains a Schedulable Series model and a relationship name. It permits transferring data conveniently by manipulating only one objet.

## Usage

### Event Service

Event service is the central service to handle events. to instanciate the `EventService` you should use [laravel container](https://laravel.com/docs/11.x/container) and Dependency injection.

From `EventService` You can, among other things, attach/detach participants, set participation status for a participant, reschedule events, cancel events.

When using `EventService` directly, events are dispatched for each previous mentioned actions :

-   `ParticipantsAttached`
-   `ParticipantsDetached`
-   `EventRescheduled`
-   `ParticipationStatusSet`

All events are dispatched inside a transaction so if you want a listener to be executed after the database commit your listener should implements `ShouldHandleEventsAfterCommit` or `ShouldQueueAfterCommit`.

### Schedulable Service

Schedulable service permit to manage events directly from your Schedulable model. to instanciate the `SchedulableService` you should use [laravel container](https://laravel.com/docs/11.x/container) and Dependency injection.

From `SchedulableService` You can, attach/detach participants, set participation status for a participant, schedule/reschedule events, cancel events.

### Schedulable Serie Service

Schedulable Serie service permit to manage a serie of events directly from your Schedulable Serie object. to instanciate the `SchedulableSerieService` you should use [laravel container](https://laravel.com/docs/11.x/container) and Dependency injection.

From `SchedulableSerieService` You can, attach/detach participants, set participation status for a participant, cancel events.

### Config

Once you have published package files you can see/edit available configs in the file `config/calendar-core.php`.

Note: If you update any api config, don't forget to reset routes cache.

### API

You can use built API routes to interact with events. If you have some particular behavior, you can use dispatched events to process some actions. if you use built API routes you can define if you want to use laravel policies, if so, you can publish prebuilt policy files and fill it as you wish.

Before using Built API routes, defined `participant_model` config MUST be a class that implement `HasScheduleInterface`. You may use `HasScheduleTrait` in your participant model so you don't have to define `events` relationship yourself.

If you have Schedulable models or Schedulable Series model associated to events, it is recommended to build your own routes and use provided services.

All API routes are defined [here](https://github.com/comhon-project/laravel-calendar-core/blob/main/routes/routes.php)

When you use routes that involve participants, you may want to scope allowed paricipants that may be synchronized on an event. To do so, you just have to register a class in the container that implements `ParticipantScoperInterface`.

```php
// in you AppServiceProvider
$this->app->bind(ParticipantScoperInterface::class, YourParticipantScoper::class);
```

## Tips

### Schedulable Series models and events aggregations

Sometimes you may want to aggregate events values for one or a bunch of Schedulable Series models. To do so, you just have to combinate [Has Many Through](https://laravel.com/docs/11.x/eloquent-relationships#has-many-through) relationships and [aggregation functions](https://laravel.com/docs/11.x/eloquent-relationships#aggregating-related-models).

For example you want to know the total duration of all training sessions for several training programs.

-   Define the relationship in the Schedulable Series model

```php
use Comhon\Calendar\Contracts\SchedulableSeriesInterface;
use Comhon\Calendar\Models\Event;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class TrainingProgram extends Model implements SchedulableSeriesInterface
{
    public function sessionEvents(): HasManyThrough
    {
        return $this->hasManyThrough(
            Event::class,
            TrainingSession::class,
            'training_program_id',
            'schedulable_id'
        )->where('schedulable_type', (new TrainingSession)->getMorphClass());
    }
}
```

-   Then call aggregation function

```php
$programs = TrainingProgram::withSum('sessionEvents', 'duration')->get();
// each program contains session_events_sum_duration property
```

### Observers

You can automatically cancel events when deleting a schedulable model or a schedualble series model. To do so you just have to use the [observer](https://laravel.com/docs/11.x/eloquent#observers) `SchedulableObserver`.

```php
#[ObservedBy([SchedulableObserver::class])]
class TrainingProgram extends Model implements SchedulableSeriesInterface
{
    ...
}

$trainingProgram->delete();
// all associated events have been automatically canceled.
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

-   [jean-philippe](https://github.com/comhon-project)
-   [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
