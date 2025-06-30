<?php

namespace App\States\Article;

use Spatie\ModelStates\State;
use Spatie\ModelStates\StateConfig;

abstract class ArticleState extends State
{
    abstract public function color(): string;

    public static function config(): StateConfig
    {
        return StateConfig::make()
            ->default(Draft::class)
            ->allowTransitions([
                [Draft::class, Review::class],
                [Review::class, Published::class],
                [Review::class, Draft::class], // 可以從審核退回草稿
                [Published::class, Draft::class], // 可以從已發布取消發布
            ]);
    }
}
