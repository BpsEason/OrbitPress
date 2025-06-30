<?php

namespace App\States\Article;

class Draft extends ArticleState
{
    public static string $name = 'draft';

    public function color(): string
    {
        return 'yellow';
    }
}
