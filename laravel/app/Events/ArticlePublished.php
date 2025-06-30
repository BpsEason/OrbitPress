<?php

namespace App\Events;

use App\Models\Tenant\Article;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ArticlePublished
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $article;

    /**
     * 創建新的事件實例。
     */
    public function __construct(Article $article)
    {
        $this->article = $article;
    }
}
