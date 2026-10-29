<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Certificate;

use App\Models\Certificate;
use App\Models\Certification;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 修了証 PDF ダウンロード(certificates.download)の HTTP 統合テスト。
 * 認可(本人 / 担当コーチ / admin)、存在しない修了証・PDF 欠落の 404、未ログインのリダイレクトを検証する。
 */
class DownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    public function test_owner_student_downloads_own_certificate_as_attachment(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->issueCertificateFor($student);

        $response = $this->actingAs($student)->get(route('certificates.download', $certificate));

        $response->assertOk();
        $response->assertDownload("certificate-{$certificate->id}.pdf");
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
    }

    public function test_graduated_student_can_download_own_certificate(): void
    {
        $student = User::factory()->student()->graduated()->create();
        $certificate = $this->issueCertificateFor($student);

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertOk();
    }

    public function test_student_cannot_download_other_student_certificate(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = $this->issueCertificateFor(User::factory()->student()->create());

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_assigned_coach_downloads_certificate_of_assigned_certification(): void
    {
        $coach = User::factory()->coach()->create();
        $certification = Certification::factory()->published()->create();
        $this->assignCoach($certification, $coach);
        $certificate = $this->issueCertificateFor(User::factory()->student()->create(), $certification);

        $this->actingAs($coach)
            ->get(route('certificates.download', $certificate))
            ->assertOk();
    }

    public function test_unassigned_coach_cannot_download_certificate(): void
    {
        $coach = User::factory()->coach()->create();
        $certificate = $this->issueCertificateFor(User::factory()->student()->create());

        $this->actingAs($coach)
            ->get(route('certificates.download', $certificate))
            ->assertForbidden();
    }

    public function test_admin_downloads_any_certificate(): void
    {
        $admin = User::factory()->admin()->create();
        $certificate = $this->issueCertificateFor(User::factory()->student()->create());

        $this->actingAs($admin)
            ->get(route('certificates.download', $certificate))
            ->assertOk();
    }

    public function test_returns_404_when_pdf_file_is_missing(): void
    {
        $student = User::factory()->student()->inProgress()->create();
        $certificate = Certificate::factory()->forEnrollment($this->passedEnrollmentFor($student))->create();

        $this->actingAs($student)
            ->get(route('certificates.download', $certificate))
            ->assertNotFound();
    }

    public function test_returns_404_when_certificate_does_not_exist(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/certificates/'.Str::ulid().'/download')
            ->assertNotFound();
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $certificate = $this->issueCertificateFor(User::factory()->student()->create());

        $this->get(route('certificates.download', $certificate))
            ->assertRedirect(route('login'));
    }

    private function issueCertificateFor(User $student, ?Certification $certification = null): Certificate
    {
        $enrollment = $this->passedEnrollmentFor($student, $certification);

        return Certificate::factory()->forEnrollment($enrollment)->withPdfFile()->create();
    }

    private function passedEnrollmentFor(User $student, ?Certification $certification = null): Enrollment
    {
        $certification ??= Certification::factory()->published()->create();

        return Enrollment::factory()->for($student)->for($certification)->passed()->create();
    }

    private function assignCoach(Certification $certification, User $coach): void
    {
        $certification->coaches()->attach($coach->id, [
            'id' => (string) Str::ulid(),
            'assigned_by_user_id' => User::factory()->admin()->create()->id,
            'assigned_at' => now(),
        ]);
    }
}
