<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Enrollment;
use App\Models\EnrollmentNote;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * EnrollmentNote リソースに対する認可ポリシー。
 *
 * - viewAny: 担当コーチ(担当資格の受講登録のみ) / 管理者(全件)
 * - create: 担当コーチ / 管理者
 * - update: 作成者本人 / 管理者
 * - delete: 作成者本人 / 管理者
 *
 * 受講生には一切の操作を禁止(Blade 側で表示も非表示)。
 * 親の受講登録が削除済みの場合は、一覧表示・追加・編集・削除のすべてを禁止する(要件9)。
 */
class EnrollmentNotePolicy
{
    /**
     * 受講登録のメモ一覧を閲覧する権限。
     * Blade の @can('viewAny', [EnrollmentNote::class, $enrollment]) で使用。
     */
    public function viewAny(User $user, Enrollment $enrollment): bool|Response
    {
        if ($enrollment->trashed()) {
            return Response::deny();
        }

        if ($user->role === UserRole::Student) {
            return Response::deny();
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Coach) {
            return $this->isAssignedCoach($enrollment, $user);
        }

        return Response::deny();
    }

    /**
     * 受講登録にメモを追加する権限。
     * Blade の @can('create', [EnrollmentNote::class, $enrollment]) で使用。
     */
    public function create(User $user, Enrollment $enrollment): bool|Response
    {
        if ($enrollment->trashed()) {
            return Response::deny();
        }

        if ($user->role === UserRole::Student) {
            return Response::deny();
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Coach) {
            return $this->isAssignedCoach($enrollment, $user)
                ? true
                : Response::deny();
        }

        return Response::deny();
    }

    /**
     * メモを更新する権限。
     * Blade の @can('update', $note) で使用。
     */
    public function update(User $user, EnrollmentNote $note): bool|Response
    {
        if ($note->enrollment->trashed()) {
            return Response::deny();
        }

        if ($user->role === UserRole::Student) {
            return Response::deny();
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Coach) {
            return $note->author_id === $user->id
                ? true
                : Response::deny();
        }

        return Response::deny();
    }

    /**
     * メモを削除する権限。
     * Blade の @can('delete', $note) で使用。
     */
    public function delete(User $user, EnrollmentNote $note): bool|Response
    {
        if ($note->enrollment->trashed()) {
            return Response::deny();
        }

        if ($user->role === UserRole::Student) {
            return Response::deny();
        }

        if ($user->role === UserRole::Admin) {
            return true;
        }

        if ($user->role === UserRole::Coach) {
            return $note->author_id === $user->id
                ? true
                : Response::deny();
        }

        return Response::deny();
    }

    /**
     * コーチが指定された受講登録の担当コーチかどうかを判定。
     */
    private function isAssignedCoach(Enrollment $enrollment, User $coach): bool
    {
        return $enrollment->certification
            ->coaches()
            ->where('users.id', $coach->id)
            ->exists();
    }
}
