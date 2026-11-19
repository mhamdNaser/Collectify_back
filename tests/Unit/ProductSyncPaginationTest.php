<?php

use App\Modules\Shopify\Pipeline\ProductSyncPipeline;
use App\Modules\Shopify\Pipeline\Stages;
use App\Modules\Stores\Models\Store;

uses(Tests\TestCase::class);

it('returns the next-page cursor and counts in the shape the import job reads', function () {
    $store = new Store();
    $fetch = Mockery::mock(Stages\FetchProductsStage::class);
    $fetch->shouldReceive('handle')->andReturn([
        'edges' => [['node' => []], ['node' => []]],
        'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'cursor-2'],
        'raw' => [],
    ]);
    $normalize = Mockery::mock(Stages\NormalizeProductsStage::class);
    $normalize->shouldReceive('handle')->andReturn([]);
    $persist = Mockery::mock(Stages\PersistProductsStage::class);
    $persist->shouldReceive('handle')->andReturn(['p1', 'p2']);
    $stage = fn (string $class) => tap(Mockery::mock($class), fn ($m) => $m->shouldReceive('handle'));

    $pipeline = new ProductSyncPipeline(
        $fetch, $normalize, $persist,
        $stage(Stages\DispatchVariantsStage::class),
        $stage(Stages\DispatchProductImagesStage::class),
        $stage(Stages\DispatchInventoryStage::class),
        $stage(Stages\DispatchMetafieldsStage::class),
        $stage(Stages\DispatchCollectionsStage::class),
    );

    expect($pipeline->run($store, 20, null))->toMatchArray([
        'fetched_count' => 2,
        'synced_count' => 2,
        'page_info' => ['has_next_page' => true, 'end_cursor' => 'cursor-2'],
    ]);
});

