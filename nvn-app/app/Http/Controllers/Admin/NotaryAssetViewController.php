<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\NotaryAsset;
use Illuminate\Support\Facades\Storage;

class NotaryAssetViewController extends Controller
{
    public function view(NotaryAsset $asset)
    {
        $path = $asset->file_url;

        abort_unless($path && Storage::disk('private')->exists($path), 404);

        return Storage::disk('private')->response($path);
    }
}
