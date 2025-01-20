<?php

namespace Outhebox\TranslationsUI\Actions;

use Illuminate\Support\Facades\Cache;
use Outhebox\TranslationsUI\Console\Commands\ImportTranslationsCommand;
use Outhebox\TranslationsUI\Models\Language;
use Outhebox\TranslationsUI\Models\Translation;
use Outhebox\TranslationsUI\Models\TranslationFile;

class SyncPhrasesAction
{
    public static function execute(Translation $source, $key, $value, $locale, $file): void
    {
        if (is_array($value) && empty($value)) {
            return;
        }

        $language = Language::where('code', $locale)->first();

        if (! $language) {
            exit;
        }

        $translation = Translation::firstOrCreate([
            'language_id' => $language->id,
            'source' => config('translations.source_language') === $locale,
        ]);

        $isRoot = $file === $locale.'.json' || $file === $locale.'.php';
        $extension = pathinfo($file, PATHINFO_EXTENSION);
        $filePath = str_replace('.'.$extension, '', str_replace($locale.DIRECTORY_SEPARATOR, '', $file));

        $translationFile = TranslationFile::firstOrCreate([
            'name' => $filePath,
            'extension' => $extension,
            'is_root' => $isRoot,
        ]);

        $key = config('translations.include_file_in_key') && ! $isRoot ? "{$translationFile->name}.{$key}" : $key;
        $time = Cache::get(ImportTranslationsCommand::CACHE_LAST_IMPORT_TIME_KEY);
        $existingPhrase = $translation->phrases()->where([
            'key' => $key,
            'group' => $translationFile->name,
            'translation_file_id' => $translationFile->id,
        ])->first();

        if (!$existingPhrase || $existingPhrase->updated_at < $time) {
            $translation->phrases()->updateOrCreate([
                'key' => $key,
                'group' => $translationFile->name,
                'translation_file_id' => $translationFile->id,
            ], [
                'value' => (empty($value) ? null : $value),
                'parameters' => getPhraseParameters($value),
                'phrase_id' => $translation->source ? null : $source->phrases()->where('key', $key)->first()?->id,
            ]);
        }
    }
}
