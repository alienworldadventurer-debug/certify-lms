<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;
use Throwable;

final class CertificatePdfService
{
    private Filesystem $disk;

    public function __construct()
    {
        $this->disk = Storage::disk('private');
    }

    /**
     * 修了証の PDF を生成し、private ディスクに保存する。
     *
     * @throws RuntimeException PDF 生成または保存に失敗した場合
     */
    public function generateAndStore(Certificate $certificate): string
    {
        $path = $certificate->pdf_path;

        try {
            $pdfContent = $this->generatePdfContent($certificate);
            $stored = $this->disk->put($path, $pdfContent);
        } catch (Throwable $e) {
            $this->cleanupFile($path);

            throw new RuntimeException('修了証 PDF の生成または保存に失敗しました。', 0, $e);
        }

        if (! $stored) {
            $this->cleanupFile($path);

            throw new RuntimeException('修了証 PDF の保存に失敗しました。');
        }

        return $path;
    }

    /**
     * 修了証の PDF バイナリ文字列を生成する。
     */
    public function generatePdfContent(Certificate $certificate): string
    {
        $certificate->loadMissing(['user', 'certification']);

        $html = view('certificates.pdf', [
            'certificate' => $certificate,
        ])->render();

        $tempDir = storage_path('framework/cache/mpdf');
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $mpdf = new Mpdf([
            'tempDir' => $tempDir,
            'mode' => 'ja',
            'format' => 'A4',
        ]);

        $mpdf->WriteHTML($html);

        return $mpdf->Output('', Destination::STRING_RETURN);
    }

    /**
     * private ディスク上の修了証 PDF を削除する。
     */
    public function delete(Certificate $certificate): void
    {
        $this->cleanupFile($certificate->pdf_path);
    }

    /**
     * private ディスク上に修了証 PDF が存在するか判定する。
     */
    public function exists(Certificate $certificate): bool
    {
        return $this->disk->exists($certificate->pdf_path);
    }

    private function cleanupFile(string $path): void
    {
        try {
            if ($this->disk->exists($path)) {
                $this->disk->delete($path);
            }
        } catch (Throwable) {
            // クリーンアップ時の例外は無視
        }
    }
}
