<?php

namespace App\Services;

use Closure;
use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * The read-only part of the Asana REST API that the import needs: single objects, paginated lists and the download of
 * a file. Requests that hit the rate limit or a temporary error are repeated after a pause.
 */
class AsanaClient
{
    public const string BASE_URL = 'https://app.asana.com/api/1.0/';

    /** The most Asana returns per page. */
    public const int PAGE_SIZE = 100;

    /** How often a request is tried before the error is given up on. */
    private const int ATTEMPTS = 6;

    /** The longest pause (seconds) between two tries, whatever Asana asks for. */
    private const int MAX_PAUSE = 120;

    /** @var ?Closure(int): void Told the seconds before the client waits, so a long pause can be shown. */
    private ?Closure $onPause = null;

    public function __construct(private readonly string $token) {}

    /**
     * @param  Closure(int): void  $callback
     */
    public function whenPausing(Closure $callback): void
    {
        $this->onPause = $callback;
    }

    /**
     * One object (the `data` of the response), e.g. a project or a task.
     *
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        $data = $this->page($path, $query)['data'] ?? [];

        return is_array($data) ? $data : [];
    }

    /**
     * All entries of a list, page after page.
     *
     * @param  array<string, scalar>  $query
     * @return Generator<int, array<string, mixed>>
     */
    public function paginate(string $path, array $query = []): Generator
    {
        $offset = null;

        do {
            $page = $this->page($path, $query + ['limit' => self::PAGE_SIZE] + ($offset === null ? [] : ['offset' => $offset]));

            foreach ($page['data'] ?? [] as $entry) {
                yield $entry;
            }

            $offset = $page['next_page']['offset'] ?? null;
        } while ($offset !== null);
    }

    /**
     * Saves a file from a temporary download address under the given path, streamed so that it never sits in memory.
     * The address is signed, so the token is not sent along (it must not reach another host); false when the file
     * cannot be fetched.
     */
    public function download(string $url, string $path): bool
    {
        if (! str_starts_with($url, 'https://')) {
            return false;
        }

        try {
            $response = $this->send($url, [], authorized: false, sink: $path);
        } catch (Throwable) {
            // The message of a failed connection names the whole address, including its signature
            throw new AsanaException('the download failed');
        }

        // The status says why (403 or 404 once the address has expired), without the address itself
        throw_unless($response->successful(), AsanaException::class, 'the download answered with HTTP '.$response->status(), $response->status());

        return true;
    }

    /**
     * @param  array<string, scalar>  $query
     * @return array<string, mixed>
     */
    private function page(string $path, array $query): array
    {
        $response = $this->send(self::BASE_URL.ltrim($path, '/'), $query, authorized: true);

        if ($response->failed()) {
            throw new AsanaException($this->describe($response, $path), $response->status());
        }

        // A captive portal or proxy page with status 200 is not an answer of Asana and must not pass for an empty list
        throw_unless(is_array($response->json()), AsanaException::class, 'Asana sent something that is not JSON (is there a proxy or login page in between?).');

        return $response->json();
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function send(string $url, array $query, bool $authorized, ?string $sink = null): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                // Without parameters the address is called as it is: an empty query would replace the signature in it
                $request = $this->request($authorized, download: $sink !== null);
                $request = $sink === null ? $request : $request->sink($sink);
                $response = $query === [] ? $request->get($url) : $request->get($url, $query);
            } catch (ConnectionException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw new AsanaException('Asana cannot be reached: '.$exception->getMessage());
                }

                $this->pause(min(2 ** $attempt, self::MAX_PAUSE));

                continue;
            }

            if ($attempt < self::ATTEMPTS && ($response->status() === 429 || $response->serverError())) {
                $this->pause(max(1, min((int) $response->header('Retry-After') ?: 2 ** $attempt, self::MAX_PAUSE)));

                continue;
            }

            return $response;
        }
    }

    private function pause(int $seconds): void
    {
        if ($this->onPause instanceof Closure) {
            ($this->onPause)($seconds);
        }

        Sleep::for($seconds)->seconds();
    }

    private function request(bool $authorized, bool $download = false): PendingRequest
    {
        // Redirects stay on https: a signed address must not lead to a plain or internal one
        $request = Http::acceptJson()->withOptions(['allow_redirects' => ['max' => 3, 'protocols' => ['https']]]);

        // A file may be as big as Asana allows, so a download has no time limit of its own, only a stalled one is given up on:
        // below 1 KB/s for a minute (curl's low speed limit). Answers of the API are small and wait 60 seconds at most.
        $request = $download
            ? $request->timeout(0)->connectTimeout(30)->withOptions(['curl' => [CURLOPT_LOW_SPEED_LIMIT => 1024, CURLOPT_LOW_SPEED_TIME => 60]])
            : $request->timeout(60);

        return $authorized ? $request->withToken($this->token) : $request;
    }

    private function describe(Response $response, string $path): string
    {
        $message = $response->json('errors.0.message') ?? 'HTTP '.$response->status();

        return match ($response->status()) {
            401 => 'Asana does not accept the token ('.$message.').',
            403 => "No access to {$path} with this token ({$message}).",
            404 => "Not found in Asana: {$path} ({$message}).",
            default => "Asana answered {$path} with an error: {$message}",
        };
    }
}
