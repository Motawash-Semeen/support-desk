<?php

/*
|--------------------------------------------------------------------------
| L5Modular layout. Modules are flat: classes in the module folder, plus
| Enums/, Models/, Requests/, routes/, resources/views and database/migrations.
| 'controllers' => '' means controllers sit in the module root.
|--------------------------------------------------------------------------
*/

return [
    'default' => [
        'routing' => ['web'],

        'structure' => [
            'controllers' => '',
            'resources' => 'Http/Resources',
            'requests' => 'Requests',
            'models' => 'Models',
            'mails' => 'Mail',
            'notifications' => '',
            'events' => 'Events',
            'listeners' => 'Listeners',
            'observers' => 'Observers',
            'jobs' => '',
            'rules' => 'Rules',
            'views' => 'resources/views',
            'translations' => 'resources/lang',
            'routes' => 'routes',
            'migrations' => 'database/migrations',
            'seeds' => 'database/seeds',
            'factories' => 'database/factories',
            'helpers' => '',
        ],
    ],

    'specific' => [],
];
