<?php

namespace App\Ai;

class EmbeddingAggregator
{
    /**
     * @param  list<list<float|int>>  $vectors
     * @return list<float>
     */
    public function aggregate(array $vectors): array
    {
        if ($vectors === []) {
            return [];
        }

        if (count($vectors) === 1) {
            return $this->normalize($vectors[0]);
        }

        $dimensions = count($vectors[0]);
        $sums = array_fill(0, $dimensions, 0.0);

        foreach ($vectors as $vector) {
            if (count($vector) !== $dimensions) {
                throw new \InvalidArgumentException('All embeddings must have the same dimensions.');
            }

            foreach ($vector as $index => $value) {
                $sums[$index] += (float) $value;
            }
        }

        $averages = array_map(
            fn (float $sum): float => $sum / count($vectors),
            $sums,
        );

        return $this->normalize($averages);
    }

    /**
     * @param  list<float|int>  $vector
     * @return list<float>
     */
    private function normalize(array $vector): array
    {
        $magnitude = sqrt(array_sum(array_map(
            fn (float|int $value): float => (float) $value * (float) $value,
            $vector,
        )));

        if ($magnitude <= 0.0) {
            return array_map(fn (float|int $value): float => (float) $value, $vector);
        }

        return array_map(
            fn (float|int $value): float => (float) $value / $magnitude,
            $vector,
        );
    }
}
