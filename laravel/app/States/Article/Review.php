<?php

namespace App\States\Article;

class Review extends ArticleState
{
    public static string $name = 'review';

    public function color(): string
    {
        return 'blue';
    }
}
