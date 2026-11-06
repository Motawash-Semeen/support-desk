<?php

arch('no debugging statements are left in the app')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'print_r', 'die'])
    ->not->toBeUsed();

arch('controllers stay thin and never open transactions or raw queries')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB']);

arch('actions and services do not depend on the HTTP layer')
    ->expect(['App\Actions', 'App\Services'])
    ->not->toUse(['Illuminate\Http\Request', 'App\Http']);

arch('form requests extend FormRequest')
    ->expect('App\Http\Requests')
    ->classes()
    ->toExtend('Illuminate\Foundation\Http\FormRequest');
