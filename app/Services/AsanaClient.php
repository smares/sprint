<?php

namespace App\Services;

use Generator;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;

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

    public function __construct(private readonly string $token) {}

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
     * The content of a file from a temporary download address. The address is signed, so the token is not sent along
     * (it must not reach another host); null when the file cannot be fetched.
     */
    public function download(string $url): ?string
    {
        if (! str_starts_with($url, 'https://')) {
            return null;
        }

        $response = $this->send($url, [], authorized: false);

        return $response->successful() ? $response->body() : null;
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

        return $response->json() ?? [];
    }

    /**
     * @param  array<string, scalar>  $query
     */
    private function send(string $url, array $query, bool $authorized): Response
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                $response = $this->request($authorized)->get($url, $query);
            } catch (ConnectionException $exception) {
                if ($attempt >= self::ATTEMPTS) {
                    throw new AsanaException('Asana cannot be reached: '.$exception->getMessage());
                }

                Sleep::for(min(2 ** $attempt, self::MAX_PAUSE))->seconds();

                continue;
            }

            if ($attempt < self::ATTEMPTS && ($response->status() === 429 || $response->serverError())) {
                Sleep::for(max(1, min((int) $response->header('Retry-After') ?: 2 ** $attempt, self::MAX_PAUSE)))->seconds();

                continue;
            }

            return $response;
        }
    }

    private function request(bool $authorized): PendingRequest
    {
        $request = Http::acceptJson()->timeout(60);

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
