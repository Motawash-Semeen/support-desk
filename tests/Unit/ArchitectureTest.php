<?php

arch('no debugging statements are left in the app')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('controllers stay thin and never open transactions or raw queries')
    ->expect(['App\Http\Controllers', ...moduleClasses('Controller')])
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('services do not depend on the HTTP layer')
    ->expect(moduleClasses('Service'))
    ->not->toUse(['Illuminate\Http\Request', 'App\Http', ...moduleNamespaces('Requests'), ...moduleClasses('Controller')]);

arch('form requests extend FormRequest')
    ->expect(['App\Http\Requests', ...moduleNamespaces('Requests')])
    ->classes()
    ->toExtend('Illuminate\Foundation\Http\FormRequest')
    ->ignoring('App\Modules\Conversation\Requests\AttachmentRules'); // shared upload rules, not a request

arch('the old layer folders stay empty: code lives in a module or the core')
    ->expect(['App\Actions', 'App\Services', 'App\Listeners', 'App\Notifications', 'App\Events', 'App\Jobs', 'App\Policies'])
    ->not->toBeUsed();

test('every module is flat: only Enums, Models and Requests sub-folders besides L5Modular\'s own', function () {
    $modules = glob(dirname(__DIR__, 2).'/app/Modules/*', GLOB_ONLYDIR);
    $allowed = ['Enums', 'Models', 'Requests', 'routes', 'resources', 'database'];

    expect($modules)->not->toBeEmpty();

    foreach ($modules as $module) {
        $folders = array_map('basename', glob($module.'/*', GLOB_ONLYDIR) ?: []);

        expect(array_diff($folders, $allowed))->toBe([], basename($module).' has an unexpected folder');

        // Module factories would make L5Modular call the removed Eloquent Factory class.
        expect(is_dir($module.'/database/factories'))->toBeFalse(basename($module).' must keep factories in database/factories');
    }
});
