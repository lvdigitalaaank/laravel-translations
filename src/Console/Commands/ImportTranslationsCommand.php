<?php

namespace Outhebox\TranslationsUI\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Schema\Builder as SchemaBuilder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Outhebox\TranslationsUI\Actions\SyncPhrasesAction;
use Outhebox\TranslationsUI\Database\Seeders\LanguagesTableSeeder;
use Outhebox\TranslationsUI\Models\Language;
use Outhebox\TranslationsUI\Models\Phrase;
use Outhebox\TranslationsUI\Models\Translation;
use Outhebox\TranslationsUI\Models\TranslationFile;
use Outhebox\TranslationsUI\TranslationsManager;

class ImportTranslationsCommand extends Command
{
    public const CACHE_LAST_IMPORT_TIME_KEY = 'translations:last-import-time';

    protected $signature = 'translations:import {--F|fresh : Truncate all translations and phrases before importing}';
    protected $description = 'Sync all translation keys from the translation files to the database';

    private TranslationsManager $manager;

    public function __construct(TranslationsManager $manager)
    {
        parent::__construct();
        $this->manager = $manager;
    }

    public function handle(): void
    {
        $this->importLanguages();

        if ($this->option('fresh') && $this->confirm('Are you sure you want to truncate all translations and phrases?')) {
            $this->info('Truncating translations and phrases...' . PHP_EOL);
            $this->truncateTables();
        }

        $sourceTranslation = $this->createOrGetSourceLanguage();
        $this->info('Importing translations...' . PHP_EOL);

        $locales = $this->manager->getLocales();
        $this->withProgressBar($locales, function (string $locale) use ($sourceTranslation) {
            $this->syncTranslations($sourceTranslation, $locale);
        });

        Cache::put(self::CACHE_LAST_IMPORT_TIME_KEY, now());
    }

    protected function importLanguages(): void
    {
        if (!$this->getSchema()->hasTable('ltu_languages') || Language::count() === 0) {
            $this->handleMissingLanguagesTable();
        }
    }

    private function handleMissingLanguagesTable(): void
    {
        if ($this->confirm('The ltu_languages table does not exist or is empty. Install default languages?', true)) {
            $this->call('db:seed', ['--class' => LanguagesTableSeeder::class]);
        } else {
            $this->error('The ltu_languages table does not exist or is empty. Run the translations:install command first.');
            exit;
        }
    }

    protected function truncateTables(): void
    {
        $this->getSchema()->withoutForeignKeyConstraints(function () {
            Phrase::truncate();
            Translation::truncate();
            TranslationFile::truncate();
        });
    }

    protected function getSchema(): SchemaBuilder
    {
        return Schema::connection(config('translations.database_connection'));
    }

    public function createOrGetSourceLanguage(): Translation
    {
        $languageCode = config('translations.source_language');
        $language = Language::where('code', $languageCode)->first();

        if (!$language) {
            $this->error("Language with code $languageCode not found." . PHP_EOL);
            exit;
        }

        $this->ensureLanguageFilesExist();

        $translation = Translation::firstOrCreate([
            'source' => true,
            'language_id' => $language->id,
        ]);

        $this->syncTranslations($translation, $languageCode);

        return $translation;
    }

    private function ensureLanguageFilesExist(): void
    {
        if (!is_dir(lang_path()) || count(scandir(lang_path())) <= 2) {
            if ($this->confirm('No languages found. Publish default language files?', true)) {
                $this->call('lang:publish');
            } else {
                $this->error('No languages found in the project. Run the lang:publish command first.');
                exit;
            }
        }
    }

    public function syncTranslations(Translation $translation, string $locale): void
    {
        $translations = $this->manager->getTranslations($locale);

        foreach ($translations as $file => $entries) {
            foreach (Arr::dot($entries) as $key => $value) {
                SyncPhrasesAction::execute($translation, $key, $value, $locale, $file);
            }
        }

        if ($locale !== config('translations.source_language')) {
            $this->syncMissingTranslations($translation, $locale);
        }
    }

    public function syncMissingTranslations(Translation $source, string $locale): void
    {
        $language = Language::where('code', $locale)->first();

        if (!$language) {
            $this->error("Language with code $locale not found.");
            return;
        }

        $translation = Translation::firstOrCreate([
            'language_id' => $language->id,
            'source' => false,
        ]);

        $source->load('phrases.translation', 'phrases.file');

        $source->phrases->each(function ($phrase) use ($translation, $locale) {
            $this->syncMissingPhrase($phrase, $translation, $locale);
        });
    }

    private function syncMissingPhrase(Phrase $phrase, Translation $translation, string $locale): void
    {
        if (!$translation->phrases()->where('key', $phrase->key)->exists()) {
            $fileName = $this->generateFileName($phrase, $locale);
            SyncPhrasesAction::execute($phrase->translation, $phrase->key, '', $locale, $fileName);
        }
    }

    private function generateFileName(Phrase $phrase, string $locale): string
    {
        $file = $phrase->file;
        $fileName = "{$file->name}.{$file->extension}";

        if ($file->name === config('translations.source_language')) {
            return Str::replaceStart(config('translations.source_language') . '.', "{$locale}.", $fileName);
        }

        return Str::replaceStart(config('translations.source_language') . '/', "{$locale}/", $fileName);
    }
}
