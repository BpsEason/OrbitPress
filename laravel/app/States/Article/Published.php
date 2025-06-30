<?php

namespace App\States\Article;

class Published extends ArticleState
{
    public static string $name = 'published';

    public function color(): string
    {
        return 'green';
    }
}
