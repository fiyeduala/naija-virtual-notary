<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaryCredential;
use Illuminate\Support\Facades\Storage;

class CredentialDownloadController extends Controller
{
    public function download(NotaryCredential $credential)
    {
        $path = $credential->file_url;

        abort_unless(Storage::disk('private')->exists($path), 404);

        $filename = $credential->original_filename ?? basename($path);

        return Storage::disk('private')->download($path, $filename);
    }
}
