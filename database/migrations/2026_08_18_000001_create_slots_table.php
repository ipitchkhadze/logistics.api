<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('slots', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('capacity');
            $table->unsignedInteger('remaining');
            $table->timestamps();
        });

        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            DB::statement(
                'ALTER TABLE slots ADD CONSTRAINT chk_slots_remaining CHECK (remaining >= 0 AND remaining <= capacity)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('slots');
    }
};
