<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use RectorLaravel\Set\LaravelLevelSetList;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    // Deliberately NOT config/ or bootstrap/. Those files ship with Laravel and
    // are replaced wholesale by framework upgrades; rewriting them turns every
    // future skeleton diff into a merge conflict for no gain. Rector's job here
    // is the code we write.
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/tests',
    ])

    // Targets the PHP the container actually runs (see Dockerfile). This is the
    // ceiling, not a floor: raising it is the thing to do after a PHP bump.
    ->withPhpSets(php85: true)

    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
    )

    ->withSets([
        // Upgrade rules through Laravel 13. No-ops on a codebase scaffolded at
        // 13 — they earn their keep at the next framework bump.
        LaravelLevelSetList::UP_TO_LARAVEL_130,

        // Idiom rules, which do apply to code written today.
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
        LaravelSetList::LARAVEL_TESTING,
        LaravelSetList::LARAVEL_ELOQUENT_MAGIC_METHOD_TO_QUERY_BUILDER,
        LaravelSetList::LARAVEL_FACADE_ALIASES_TO_FULL_NAMES,
        LaravelSetList::LARAVEL_ARRAY_STR_FUNCTION_TO_STATIC_CALL,
        LaravelSetList::LARAVEL_CONTAINER_STRING_TO_FULLY_QUALIFIED_NAME,
    ]);

// Two Laravel sets are left out on purpose:
//
// LARAVEL_STATIC_TO_INJECTION rewrites facade calls into constructor injection.
// That is a repo-wide architectural opinion, not a cleanup, and it fights both
// Filament and the framework's own documented idiom.
//
// LARAVEL_LEGACY_FACTORIES_TO_CLASSES targets pre-8.0 factories. There are none
// here and never will be.
