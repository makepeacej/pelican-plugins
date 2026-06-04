<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('theme_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('user_id')->unique();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->string('theme_id');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('theme_preferences');
    }
};
