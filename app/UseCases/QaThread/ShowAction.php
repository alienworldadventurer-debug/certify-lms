<?php

declare(strict_types=1);

namespace App\UseCases\QaThread;

use App\Models\QaThread;

final class ShowAction
{
    public function __invoke(QaThread $thread): QaThread
    {
        return $thread->load([
            'certification',
            'user',
            'replies.user',
        ])->loadCount('replies');
    }
}
