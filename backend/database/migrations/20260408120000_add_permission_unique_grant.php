<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddPermissionUniqueGrant extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            CREATE UNIQUE INDEX IF NOT EXISTS ux_permission_grant
            ON permission (target_type, target_id, grantee_type, grantee_id);
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS ux_permission_grant;');
    }
}

