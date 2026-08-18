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
        if (! $this->isMysqlFamily()) {
            return;
        }

        if (! Schema::hasIndex('holds', 'holds_status_expires_at_slot_id_index')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->index(['status', 'expires_at', 'slot_id'], 'holds_status_expires_at_slot_id_index');
            });
        }

        if (! $this->hasCheckConstraint('holds', 'chk_holds_status')) {
            DB::statement(
                "ALTER TABLE holds ADD CONSTRAINT chk_holds_status CHECK (status IN ('held', 'confirmed', 'cancelled'))"
            );
        }
    }

    public function down(): void
    {
        if (! $this->isMysqlFamily()) {
            return;
        }

        if ($this->hasCheckConstraint('holds', 'chk_holds_status')) {
            DB::statement('ALTER TABLE holds DROP CHECK chk_holds_status');
        }

        if (Schema::hasIndex('holds', 'holds_status_expires_at_slot_id_index')) {
            Schema::table('holds', function (Blueprint $table) {
                $table->dropIndex('holds_status_expires_at_slot_id_index');
            });
        }
    }

    private function isMysqlFamily(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }

    private function hasCheckConstraint(string $table, string $name): bool
    {
        $row = DB::selectOne(
            'SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = ?
               AND CONSTRAINT_NAME = ?
               AND CONSTRAINT_TYPE = ?',
            [$table, $name, 'CHECK']
        );

        return $row !== null;
    }
};
