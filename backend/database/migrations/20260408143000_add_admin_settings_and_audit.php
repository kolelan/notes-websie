<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddAdminSettingsAndAudit extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS system_setting (
                key VARCHAR(120) PRIMARY KEY,
                value JSONB NOT NULL,
                updated_by UUID NULL REFERENCES "user"(id) ON DELETE SET NULL,
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS audit_log (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                actor_user_id UUID NULL REFERENCES "user"(id) ON DELETE SET NULL,
                action VARCHAR(120) NOT NULL,
                target_type VARCHAR(60) NOT NULL,
                target_id VARCHAR(120) NULL,
                details JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
        SQL);

        $this->execute('CREATE INDEX IF NOT EXISTS idx_audit_log_actor ON audit_log(actor_user_id);');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at DESC);');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS audit_log;');
        $this->execute('DROP TABLE IF EXISTS system_setting;');
    }
}
