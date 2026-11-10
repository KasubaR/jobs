<?php

namespace App\Ai\Evaluation;

/**
 * The adverts used to test the extraction prompt: real Zambian adverts and the answer a person gave for each
 * (database/evaluation/adverts/manifest.php). An advert marked `holdout` is kept back for one final score.
 */
class AdvertSet
{
    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= base_path('database/evaluation/adverts');
    }

    /**
     * @return list<array{file:string, title:string, sector:string, split:string, multi_position:bool, hard:list<string>, expected:array<string, mixed>, text:string}>
     */
    public function all(): array
    {
        return array_map(fn (array $ad) => $ad + ['text' => file_get_contents($this->dir.'/'.$ad['file'])], require $this->dir.'/manifest.php');
    }

    /** @param 'tune'|'holdout'|'all' $split */
    public function split(string $split = 'tune', bool $withMultiPosition = false): array
    {
        return array_values(array_filter(
            $this->all(),
            fn (array $ad) => ($split === 'all' || $ad['split'] === $split) && ($withMultiPosition || ! $ad['multi_position']),
        ));
    }
}
