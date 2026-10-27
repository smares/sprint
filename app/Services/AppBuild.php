<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Which build of the app is running: a hash of the release and of the built assets, so every deployment (and every new
 * asset build) has another one. The service worker takes it as its version, and every answer carries it, so that a page
 * loaded before a deployment can tell it is out of date (see MarkAppResponse and the banner in the layout).
 * Only a hash, so that the exact version is not handed out as such (the manifest and the version could still be tried: it is no protection).
 */
class AppBuild
{
    private ?string $id = null;

    public function id(): string
    {
        return $this->id ??= substr(md5(config('sprint.version').(json_encode($this->viteManifest()) ?: '')), 0, 12);
    }

    /**
     * @return array<string, array{file?: string, css?: list<string>}>
     */
    public function viteManifest(): array
    {
        $path = public_path('build/manifest.json');

        return File::exists($path) ? (array) json_decode(File::get($path), true) : [];
    }
}
