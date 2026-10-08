<?php

declare(strict_types=1);

namespace App\UseCases\MeetingPack;

use App\Models\MeetingPack;

final class ShowAction
{
    public function __invoke(MeetingPack $meetingPack): MeetingPack
    {
        return $meetingPack->load(['createdBy', 'updatedBy']);
    }
}
