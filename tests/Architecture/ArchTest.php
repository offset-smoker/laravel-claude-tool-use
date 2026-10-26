<?php

declare(strict_types=1);

arch()->preset()->php();

arch()->preset()->security();

// Eloquent models keep their framework-defined protected casts() method.
arch()->preset()->strict()
    ->ignoring(['App\Models', 'App\Assistant\Tools\Tool']);

arch('models are final')
    ->expect('App\Models')
    ->toBeFinal()
    ->toUseStrictTypes();

arch('the tool loop never approves or rejects a release')
    ->expect('App\Assistant')
    ->not->toUse(['App\Actions\ApproveRelease', 'App\Actions\RejectRelease']);

arch('application code reads config, never env')
    ->expect('App')
    ->not->toUse('env');

arch('only the usage recorder writes logs from the assistant')
    ->expect('App\Assistant')
    ->not->toUse(['Illuminate\Support\Facades\Log', 'logger', 'info']);
