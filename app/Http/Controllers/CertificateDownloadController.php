<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Certificate;
use App\Services\CertificatePdfService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * 修了証 PDF のダウンロード Controller。
 *
 * 認可は CertificatePolicy::download(本人 / 担当コーチ / admin) で判定する。
 * 判定順は 修了証の存在(ルートモデル結合) → 認可(403) → PDF ファイルの存在(404)。
 * 配信はファイル添付形式(保存させる)とし、ファイル名は certificate-{修了証 ID}.pdf とする。
 */
class CertificateDownloadController extends Controller
{
    public function download(Certificate $certificate, CertificatePdfService $pdfService): StreamedResponse
    {
        $this->authorize('download', $certificate);

        abort_unless($pdfService->exists($certificate), 404);

        return Storage::disk('private')->download(
            $certificate->pdf_path,
            "certificate-{$certificate->id}.pdf",
        );
    }
}
