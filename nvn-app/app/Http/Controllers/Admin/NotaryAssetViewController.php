<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaryAsset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

class NotaryAssetViewController extends Controller
{
    public function view(NotaryAsset $asset)
    {
        // Said again here and not only on the route. These stream a notary
        // seal, signature or licence document straight off the private disk,
        // and a route moved into the wrong middleware group one day must not
        // be all that stands between those files and a client session.
        abort_unless(Auth::user()?->isAdmin(), 403);

        $path = $asset->file_url;

        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response($path);
    }
}
