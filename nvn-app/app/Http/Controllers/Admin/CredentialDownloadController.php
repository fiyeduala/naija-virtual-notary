<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaryCredential;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class CredentialDownloadController extends Controller
{
    public function download(NotaryCredential $credential)
    {
        // Said again here and not only on the route. These stream a notary
        // seal, signature or licence document straight off the private disk,
        // and a route moved into the wrong middleware group one day must not
        // be all that stands between those files and a client session.
        abort_unless(Auth::user()?->isAdmin(), 403);

        $path = $credential->file_url;

        abort_unless(Storage::disk('private')->exists($path), 404);

        $filename = $credential->original_filename ?? basename($path);

        return Storage::disk('private')->download($path, $filename);
    }
}
