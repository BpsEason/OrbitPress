<?php

namespace App\Events;

use App\Models\Tenant\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserSubscribed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $user;

    /**
     * 創建新的事件實例。
     */
    public function __construct(User $user)
    {
        $this->user = $user;
    }
}
