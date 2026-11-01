<?php

declare(strict_types=1);

namespace App\UseCases\Meeting;

use App\Models\Meeting;

/**
 * 面談詳細の表示に必要なリレーションを読み込む Action。閲覧権限は Controller で Policy により確認する。
 */
final class ShowAction
{
    public function __invoke(Meeting $meeting): Meeting
    {
        return $meeting->loadMissing([
            'enrollment.certification',
            'coach',
            'student',
            'canceledBy',
            'meetingMemo',
        ]);
    }
}
