<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * Sprint as an app on the home screen: the web app manifest, the service worker and the page it shows without a connection.
 * The service worker keeps the built assets and the offline page; pages and Livewire requests always go to the server.
 */
class ProgressiveWebAppController extends Controller
{
    /** The Vite entries every page loads; their files and the offline page are cached when the service worker installs. */
    private const array ENTRIES = ['resources/css/app.css', 'resources/js/app.js', 'resources/js/realtime.js'];

    private const array ICONS = ['/icon-192.png', '/icon-512.png', '/icon-maskable-512.png'];

    public function manifest(): JsonResponse
    {
        return response()->json([
            'id' => '/',
            'name' => config('app.name'),
            'short_name' => config('app.name'),
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'background_color' => '#18181b',
            'theme_color' => '#18181b',
            'icons' => [
                ['src' => '/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/icon-maskable-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
        ], options: JSON_UNESCAPED_SLASHES)->header('Content-Type', 'application/manifest+json');
    }

    /**
     * The service worker with the version and the files to keep in front: a new build changes the version,
     * so the browser installs the new worker and drops the files of the old build.
     */
    public function serviceWorker(): Response
    {
        $precache = [route('offline', absolute: false), ...$this->builtAssets(), ...self::ICONS];

        $script = 'const VERSION = '.json_encode($this->version()).";\n"
            .'const PRECACHE = '.json_encode($precache, JSON_UNESCAPED_SLASHES).";\n"
            .'const OFFLINE_URL = '.json_encode(route('offline', absolute: false), JSON_UNESCAPED_SLASHES).";\n\n"
            .File::get(resource_path('js/service-worker.js'));

        return response($script, 200, [
            'Content-Type' => 'text/javascript; charset=utf-8',
            'Cache-Control' => 'no-cache',
        ]);
    }

    public function offline(): View
    {
        return view('offline');
    }

    /**
     * @return list<string>
     */
    private function builtAssets(): array
    {
        $manifest = $this->viteManifest();

        return collect(self::ENTRIES)
            ->filter(fn (string $entry) => isset($manifest[$entry]['file']))
            ->flatMap(fn (string $entry) => [$manifest[$entry]['file'], ...($manifest[$entry]['css'] ?? [])])
            ->map(fn (string $file) => '/build/'.$file)
            ->unique()
            ->values()
            ->all();
    }

    private function version(): string
    {
        return config('sprint.version').'-'.substr(md5(json_encode($this->viteManifest()) ?: ''), 0, 12);
    }

    /**
     * @return array<string, array{file?: string, css?: list<string>}>
     */
    private function viteManifest(): array
    {
        $path = public_path('build/manifest.json');

        return File::exists($path) ? (array) json_decode(File::get($path), true) : [];
    }
}
