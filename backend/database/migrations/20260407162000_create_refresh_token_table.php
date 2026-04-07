<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateRefreshTokenTable extends AbstractMigration
{
    public function up(): void
    {
        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS refresh_token (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                user_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
                token TEXT NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                revoked_at TIMESTAMPTZ NULL
            );
        SQL);

        $this->execute('CREATE INDEX IF NOT EXISTS idx_refresh_token_user_id ON refresh_token(user_id);');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_refresh_token_expires_at ON refresh_token(expires_at);');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS refresh_token;');
    }
}
