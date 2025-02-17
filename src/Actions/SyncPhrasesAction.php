<?php

namespace Outhebox\TranslationsUI\Actions;

use Outhebox\TranslationsUI\Models\Language;
use Outhebox\TranslationsUI\Models\Translation;
use Outhebox\TranslationsUI\Models\TranslationFile;

class SyncPhrasesAction
{
    public static function execute(Translation $source, string $key, $value, string $locale, string $file, bool $overwrite = true): void
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
        $filePath = str_replace('.'.$extension, '', preg_replace('/^'.preg_quote($locale.DIRECTORY_SEPARATOR, '/').'/', '', $file));

        $translationFile = TranslationFile::firstOrCreate([
            'name' => $filePath,
            'extension' => $extension,
            'is_root' => $isRoot,
        ]);

        $key = config('translations.include_file_in_key') && ! $isRoot ? "{$translationFile->name}.{$key}" : $key;
        $method = $overwrite ? 'updateOrCreate' : 'firstOrCreate';
        $translation->phrases()->$method([
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
