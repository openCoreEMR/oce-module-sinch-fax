<?php

declare(strict_types=1);

use Rector\Caching\ValueObject\Storage\FileCacheStorage;
use Rector\CodeQuality\Rector\If_\SimplifyIfElseToTernaryRector;
use Rector\Config\RectorConfig;
use Rector\Php80\Rector\Class_\ClassPropertyAssignToConstructorPromotionRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
        __DIR__ . '/public',
    ])
    ->withCache(
        cacheClass: FileCacheStorage::class,
        cacheDirectory: '/tmp/rector'
    )
    ->withCodeQualityLevel(5)
    ->withConfiguredRule(ClassPropertyAssignToConstructorPromotionRector::class, [
        'allow_model_based_classes' => true,
        'inline_public' => false,
        'rename_property' => true,
    ])
    ->withDeadCodeLevel(5)
    ->withParallel(
        timeoutSeconds: 120,
        maxNumberOfProcess: 12,
        jobSize: 12
    )
    ->withPhpVersion(PhpVersion::PHP_82)
    ->withRules([
        SimplifyIfElseToTernaryRector::class,
    ])
    ->withPhpSets()
    ->withSkip([
        __DIR__ . '/vendor',
    ])
    ->withTypeCoverageLevel(5);
