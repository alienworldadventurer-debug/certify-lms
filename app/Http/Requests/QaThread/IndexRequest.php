<?php

declare(strict_types=1);

namespace App\Http\Requests\QaThread;

use App\Enums\CertificationStatus;
use App\Enums\UserRole;
use App\Models\Certification;
use App\Models\QaThread;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Str;

class IndexRequest extends FormRequest
{
    private bool $hasInvalidFilter = false;

    public function authorize(): bool
    {
        return $this->user()?->can('viewAny', QaThread::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $query = $this->query();
        $user = $this->user();

        if (array_key_exists('certification_id', $query)
            && $query['certification_id'] !== null
            && $query['certification_id'] !== '') {
            $certificationId = $query['certification_id'];
            $certification = is_string($certificationId) && Str::isUlid($certificationId)
                ? Certification::query()->find($certificationId)
                : null;

            if ($certification === null) {
                $this->removeInvalidQueryParameter($query, 'certification_id');
            } elseif (
                $user !== null
                && $user->role !== UserRole::Admin
                && (
                    $certification->status !== CertificationStatus::Published
                    || ! $user->can('view', $certification)
                )
            ) {
                abort(403);
            }
        }

        if (array_key_exists('status', $query)
            && $query['status'] !== null
            && $query['status'] !== ''
            && ! in_array($query['status'], ['unresolved', 'resolved'], true)) {
            $this->removeInvalidQueryParameter($query, 'status');
        }

        if ($this->hasInvalidFilter) {
            $query = array_filter(
                $query,
                static fn (mixed $value): bool => $value !== null && $value !== '',
            );
            $routeName = $this->routeIs('admin.*')
                ? 'admin.qa-board.index'
                : 'qa-board.index';

            throw new HttpResponseException(
                redirect()
                    ->route($routeName, $query)
                    ->with('warning', '指定された絞り込み条件が無効なため、その条件を適用せずに表示しています。'),
            );
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'certification_id' => ['nullable', 'ulid'],
            'status' => ['nullable', 'in:unresolved,resolved'],
            'keyword' => ['nullable', 'string'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array{certification_id: ?string, status: ?string, keyword: ?string}
     */
    public function filters(): array
    {
        return [
            'certification_id' => $this->input('certification_id'),
            'status' => $this->input('status'),
            'keyword' => $this->input('keyword'),
        ];
    }

    /**
     * @param array<string, mixed> $query
     */
    private function removeInvalidQueryParameter(array &$query, string $parameter): void
    {
        unset($query[$parameter]);
        $this->hasInvalidFilter = true;
    }
}
