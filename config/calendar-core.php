<?php

// config for Comhon/Calendar
return [
    /*
     | prefix to apply on event routes
     */
    'route_prefix' => '',

    /*
     | middlewares to apply on all event routes
     */
    'middleware' => ['web', 'auth'],

    /*
     | if you want to define user access using policies, set this config to true.
     | don't forget to publish policies in your project in order to use policies.
     */
    'use_policies' => false,

    'participant_model' => \App\Models\User::class,
    'creator_model' => \App\Models\User::class,
];
