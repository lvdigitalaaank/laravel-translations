<?php

declare(strict_types=1);

namespace Outhebox\TranslationsUI\Actions;

use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use Outhebox\TranslationsUI\Console\Commands\ImportTranslationsCommand;
use Outhebox\TranslationsUI\Models\Language;
use Outhebox\TranslationsUI\Models\Translation;
use Outhebox\TranslationsUI\Models\TranslationFile;
use RuntimeException;

class SyncPhrasesAction
{
    private const SUPPORTED_EXTENSIONS = ['json', 'php'];

    /**
     * Synchronize translation phrases for a given source, key, value, locale and file.
     *
     * @param Translation $source The source translation
     * @param string $key The translation key
     * @param mixed $value The translation value
     * @param string $locale The locale code
     * @param string $file The file path
     * @throws InvalidArgumentException|RuntimeException
     * @return void
     */
    public static function execute(Translation $source, string $key, mixed $value, string $locale, string $file): void
    {
        // Skip empty array values
        if (is_array($value) && empty($value)) {
            return;
        }

        // Validate file extension
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        if (!in_array($extension, self::SUPPORTED_EXTENSIONS)) {
            throw new InvalidArgumentException("Unsupported file extension: {$extension}");
        }

        $language = self::getLanguage($locale);
        $translation = self::getOrCreateTranslation($language, $locale);
        $translationFile = self::getOrCreateTranslationFile($file, $locale, $extension);

        $key = self::formatKey($key, $translationFile);

        self::updateTranslationPhrase(
            $translation,
            $source,
            $key,
            $value,
            $translationFile
        );
    }

    /**
     * Get the language model for the given locale.
     */
    private static function getLanguage(string $locale): Language
    {
        $language = Language::where('code', $locale)->first();

        if (!$language) {
            throw new RuntimeException("Language not found for locale: {$locale}");
        }

        return $language;
    }

    /**
     * Get or create a translation for the given language.
     */
    private static function getOrCreateTranslation(Language $language, string $locale): Translation
    {
        return Translation::firstOrCreate([
            'language_id' => $language->id,
            'source' => config('translations.source_language') === $locale,
        ]);
    }

    /**
     * Get or create a translation file record.
     */
    private static function getOrCreateTranslationFile(string $file, string $locale, string $extension): TranslationFile
    {
        $isRoot = $file === "{$locale}.{$extension}";
        $filePath = str_replace(
            ".{$extension}",
            '',
            str_replace($locale.DIRECTORY_SEPARATOR, '', $file)
        );

        return TranslationFile::firstOrCreate([
            'name' => $filePath,
            'extension' => $extension,
            'is_root' => $isRoot,
        ]);
    }

    /**
     * Format the translation key based on configuration.
     */
    private static function formatKey(string $key, TranslationFile $file): string
    {
        if (config('translations.include_file_in_key') && !$file->is_root) {
            return "{$file->name}.{$key}";
        }

        return $key;
    }

    /**
     * Update or create the translation phrase.
     */
    private static function updateTranslationPhrase(
        Translation $translation,
        Translation $source,
        string $key,
        mixed $value,
        TranslationFile $file
    ): void {

        $existingPhrase = $translation->phrases()
            ->where([
                'key' => $key,
                'group' => $file->name,
                'translation_file_id' => $file->id,
            ])
            ->first();

        if (!$existingPhrase || !$existingPhrase->changed) {
            $translation->phrases()->updateOrCreate(
                [
                    'key' => $key,
                    'group' => $file->name,
                    'translation_file_id' => $file->id,
                ],
                [
                    'value' => empty($value) ? null : $value,
                    'parameters' => getPhraseParameters($value),
                    'phrase_id' => $translation->source ? null : $source->phrases()->where('key', $key)->first()?->id,
                ]
            );
        }
    }
}
