<?php

namespace App\Http\Controllers\Qa;

use App\Domain\Qa\ManualChecklist;
use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * GET /qa/checklist/files/{file}: a test file of the manual checklist
 * (DESIGN §39.7), for signed-in Test Lab accounts only.
 */
class ManualChecklistFileController extends Controller
{
    public function __invoke(string $file): BinaryFileResponse
    {
        $path = ManualChecklist::file($file);
        abort_if($path === null, 404);

        return response()->download($path, $file, ['Content-Type' => 'application/pdf']);
    }
}
