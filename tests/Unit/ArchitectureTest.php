<?php

arch('no debugging statements are left in the app')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('controllers stay thin and never open transactions or raw queries')
    ->expect(['App\Http\Controllers', ...moduleNamespaces('Http\Controllers')])
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('actions and services do not depend on the HTTP layer')
    ->expect([...moduleNamespaces('Actions'), ...moduleNamespaces('Services')])
    ->not->toUse(['Illuminate\Http\Request', 'App\Http', ...moduleNamespaces('Http')]);

arch('form requests extend FormRequest')
    ->expect(['App\Http\Requests', ...moduleNamespaces('Http\Requests')])
    ->classes()
    ->toExtend('Illuminate\Foundation\Http\FormRequest');

arch('the old layer folders stay empty: code lives in a module or the core')
    ->expect(['App\Actions', 'App\Services', 'App\Listeners', 'App\Notifications', 'App\Events', 'App\Jobs', 'App\Policies'])
    ->not->toBeUsed();

test('every module follows the L5Modular layout', function () {
    $modules = glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR);

    expect($modules)->not->toBeEmpty();

    foreach ($modules as $module) {
        // Module factories would make L5Modular call the removed Eloquent Factory class.
        expect(is_dir($module.'/database/factories'))->toBeFalse(basename($module).' must keep factories in database/factories');
    }
});
