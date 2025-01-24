<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection()
    {
        $connection = config('translations.database_connection');

        return $connection ?? $this->connection;
    }

    public function up(): void
    {
        Schema::table('ltu_phrases', function (Blueprint $table) {
            $table->boolean('changed')->default(false)->after('note');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ltu_translations');
    }
};
