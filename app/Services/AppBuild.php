<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Which build of the app is running: a hash of the release and of the built assets, so every deployment (and every new
 * asset build) has another one. The service worker takes it as its version, and every answer carries it, so that a page
 * loaded before a deployment can tell it is out of date (see MarkAppResponse and the banner in the layout).
 * Only a hash: the exact version is for signed-in people (it would help to pick known flaws).
 */
class AppBuild
{
    private static ?string $id = null;

    public function id(): string
    {
        return self::$id ??= substr(md5(config('sprint.version').(json_encode($this->viteManifest()) ?: '')), 0, 12);
    }

    /**
     * @return array<string, array{file?: string, css?: list<string>}>
     */
    public function viteManifest(): array
    {
        $path = public_path('build/manifest.json');

        return File::exists($path) ? (array) json_decode(File::get($path), true) : [];
    }

    /** For tests, which change the version or the manifest within one process. */
    public static function forget(): void
    {
        self::$id = null;
    }
}
