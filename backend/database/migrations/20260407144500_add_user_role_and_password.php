<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddUserRoleAndPassword extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            ALTER TABLE "user"
            ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) NULL,
            ADD COLUMN IF NOT EXISTS role VARCHAR(32) NOT NULL DEFAULT 'user',
            ADD COLUMN IF NOT EXISTS is_active BOOLEAN NOT NULL DEFAULT TRUE;
        SQL);

        $this->execute('CREATE INDEX IF NOT EXISTS idx_user_role ON "user"(role);');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS idx_user_role;');
        $this->execute(<<<SQL
            ALTER TABLE "user"
            DROP COLUMN IF EXISTS is_active,
            DROP COLUMN IF EXISTS role,
            DROP COLUMN IF EXISTS password_hash;
        SQL);
    }
}
