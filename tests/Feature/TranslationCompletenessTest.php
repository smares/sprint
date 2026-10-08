<?php

namespace Tests\Feature;

use App\Color;
use App\Services\LocaleService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TranslationCompletenessTest extends TestCase
{
    /**
     * @return array<string, string>
     */
    private function german(): array
    {
        return json_decode(File::get(base_path('lang/de.json')), true, flags: JSON_THROW_ON_ERROR);
    }

    /**
     * Every text passed to __() or trans_choice() in the code, with the file it comes from.
     *
     * @param  list<string>  $directories  Further directories to look in.
     * @return array<string, string>
     */
    private function usedKeys(array $directories = []): array
    {
        $keys = [];
        $call = '/(?:__|trans_choice|@lang)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")/';

        foreach ([app_path(), resource_path('views'), base_path('routes'), ...$directories] as $directory) {
            foreach (File::allFiles($directory) as $file) {
                if (! str_ends_with($file->getFilename(), '.php') || str_contains($file->getPathname(), '/views/mail/')) {
                    continue;
                }

                preg_match_all($call, $file->getContents(), $matches, PREG_SET_ORDER);

                foreach ($matches as $match) {
                    $key = $match[1] !== '' ? str_replace("\\'", "'", $match[1]) : stripcslashes($match[2] ?? '');

                    if (! preg_match('/^[a-z_]+\.[a-z_.]+$/', $key)) {
                        $keys[$key] = $file->getRelativePathname();
                    }
                }
            }
        }

        return $keys;
    }

    public function test_every_text_in_the_code_has_a_german_translation(): void
    {
        $german = $this->german();
        $missing = array_filter($this->usedKeys(), fn ($file, $key) => ! array_key_exists($key, $german), ARRAY_FILTER_USE_BOTH);

        $this->assertSame([], $missing, 'Texts without a German translation in lang/de.json');
    }

    public function test_every_german_translation_is_still_used(): void
    {
        // Besides our code: Flux's own texts, Laravel's mail layout and the color names, which are translated from Color::SWATCHES.
        $used = $this->usedKeys([
            base_path('vendor/livewire/flux/stubs'),
            base_path('vendor/livewire/flux-pro/stubs'),
            base_path('vendor/laravel/framework/src/Illuminate/Mail/resources/views'),
        ]) + array_fill_keys(array_column(Color::SWATCHES, 1), 'app/Color.php');

        $this->assertSame([], array_values(array_diff(array_keys($this->german()), array_keys($used))), 'Translations in lang/de.json that nothing uses');
    }

    public function test_placeholders_and_plural_forms_match_between_text_and_translation(): void
    {
        $problems = [];

        foreach ($this->german() as $key => $translation) {
            preg_match_all('/:[a-z_]+/i', $key, $english);
            preg_match_all('/:[a-z_]+/i', $translation, $german);

            if (array_unique($english[0]) !== array_unique($german[0]) && array_diff($english[0], $german[0]) !== []) {
                $problems[] = "placeholders differ: {$key}";
            }

            if (str_contains($key, '|') && substr_count($key, '|') !== substr_count($translation, '|')) {
                $problems[] = "plural forms differ: {$key}";
            }
        }

        $this->assertSame([], $problems);
    }

    public function test_every_language_has_laravels_own_texts_and_the_mail_templates(): void
    {
        $english = array_keys(require base_path('lang/en/validation.php'));

        foreach (LocaleService::codes() as $code) {
            $this->assertDirectoryExists(base_path("lang/{$code}"));
            $this->assertSame([], array_values(array_diff($english, array_keys(require base_path("lang/{$code}/validation.php")))), "validation texts missing for {$code}");

            foreach (['task-commented', 'task-status-changed', 'tasks-status-changed', 'user-mentioned', 'daily-digest'] as $mail) {
                $this->assertFileExists(resource_path("views/mail/{$code}/{$mail}.blade.php"));
                $this->assertFileExists(resource_path("views/mail/{$code}/subjects/{$mail}.blade.php"));
            }
        }
    }

    public function test_the_german_file_is_valid_without_empty_values(): void
    {
        $german = $this->german();

        $this->assertNotEmpty($german);
        $this->assertSame([], array_keys(array_filter($german, fn (string $value) => trim($value) === '')));
    }
}
