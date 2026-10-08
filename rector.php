<?php

use Rector\Config\RectorConfig;
use Rector\TypeDeclaration\Rector\ArrowFunction\AddArrowFunctionReturnTypeRector;
use Rector\TypeDeclaration\Rector\Closure\AddClosureVoidReturnTypeWhereNoReturnRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;
use RectorLaravel\Rector\ClassMethod\ScopeNamedClassMethodToScopeAttributedClassMethodRector;
use RectorLaravel\Rector\FuncCall\AppToResolveRector;
use RectorLaravel\Rector\MethodCall\AssertSeeToAssertSeeHtmlRector;
use RectorLaravel\Rector\StaticCall\CarbonToDateFacadeRector;
use RectorLaravel\Set\LaravelSetList;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/app',
        __DIR__.'/bootstrap/app.php',
        __DIR__.'/config',
        __DIR__.'/database',
        __DIR__.'/routes',
        __DIR__.'/tests',
    ])
    // Rules that would change conventions the codebase follows consistently:
    // no strict_types declarations, untyped closures, app() instead of resolve(),
    // Carbon instead of the Date facade, scopeName() methods instead of #[Scope].
    ->withSkip([
        SafeDeclareStrictTypesRector::class,
        AddClosureVoidReturnTypeWhereNoReturnRector::class,
        AddArrowFunctionReturnTypeRector::class,
        AppToResolveRector::class,
        AssertSeeToAssertSeeHtmlRector::class,
        CarbonToDateFacadeRector::class,
        ScopeNamedClassMethodToScopeAttributedClassMethodRector::class,
    ])
    ->withPhpSets(php83: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withComposerBased(laravel: true)
    ->withSets([
        LaravelSetList::LARAVEL_CODE_QUALITY,
        LaravelSetList::LARAVEL_COLLECTION,
        LaravelSetList::LARAVEL_IF_HELPERS,
        LaravelSetList::LARAVEL_TYPE_DECLARATIONS,
    ])
    ->withImportNames(removeUnusedImports: true);
