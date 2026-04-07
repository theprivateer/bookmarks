<?php

use App\Ai\EmbeddingAggregator;

test('it averages and normalizes embeddings deterministically', function () {
    $aggregated = (new EmbeddingAggregator)->aggregate([
        [1.0, 0.0, 0.0],
        [0.0, 1.0, 0.0],
    ]);

    expect($aggregated)->toHaveCount(3)
        ->and($aggregated[0])->toBe(0.7071067811865475)
        ->and($aggregated[1])->toBe(0.7071067811865475)
        ->and($aggregated[2])->toBe(0.0);
});

test('it preserves the embedding dimension count', function () {
    $aggregated = (new EmbeddingAggregator)->aggregate([
        [1.0, 2.0, 3.0, 4.0],
        [4.0, 3.0, 2.0, 1.0],
        [0.5, 0.5, 0.5, 0.5],
    ]);

    expect($aggregated)->toHaveCount(4);
});
