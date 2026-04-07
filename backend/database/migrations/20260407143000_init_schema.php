<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class InitSchema extends AbstractMigration
{
    public function up(): void
    {
        $this->execute('CREATE EXTENSION IF NOT EXISTS "pgcrypto";');

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS "user" (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                email VARCHAR(255) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL,
                auth_providers JSONB NOT NULL DEFAULT '{}'::jsonb,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS "group" (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                name VARCHAR(255) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                image_url TEXT NULL,
                parent_id UUID NULL REFERENCES "group"(id) ON DELETE SET NULL,
                owner_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS note (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                title VARCHAR(255) NOT NULL,
                description TEXT NOT NULL DEFAULT '',
                content TEXT NOT NULL DEFAULT '',
                image_preview_url TEXT NULL,
                owner_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS note_group (
                note_id UUID NOT NULL REFERENCES note(id) ON DELETE CASCADE,
                group_id UUID NOT NULL REFERENCES "group"(id) ON DELETE CASCADE,
                is_copy BOOLEAN NOT NULL DEFAULT FALSE,
                PRIMARY KEY (note_id, group_id)
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS tag (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                name VARCHAR(100) NOT NULL UNIQUE
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS note_tag (
                note_id UUID NOT NULL REFERENCES note(id) ON DELETE CASCADE,
                tag_id UUID NOT NULL REFERENCES tag(id) ON DELETE CASCADE,
                PRIMARY KEY (note_id, tag_id)
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS user_group (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                name VARCHAR(255) NOT NULL,
                owner_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS user_group_member (
                user_group_id UUID NOT NULL REFERENCES user_group(id) ON DELETE CASCADE,
                user_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
                PRIMARY KEY (user_group_id, user_id)
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS permission (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                target_type VARCHAR(20) NOT NULL CHECK (target_type IN ('note', 'group')),
                target_id UUID NOT NULL,
                grantee_type VARCHAR(20) NOT NULL CHECK (grantee_type IN ('user', 'group_of_users', 'public')),
                grantee_id UUID NULL,
                can_read BOOLEAN NOT NULL DEFAULT FALSE,
                can_edit BOOLEAN NOT NULL DEFAULT FALSE,
                can_manage BOOLEAN NOT NULL DEFAULT FALSE,
                can_transfer BOOLEAN NOT NULL DEFAULT FALSE,
                inherited_from UUID NULL
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS invitation (
                id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
                target_group_id UUID NOT NULL REFERENCES "group"(id) ON DELETE CASCADE,
                inviter_id UUID NOT NULL REFERENCES "user"(id) ON DELETE CASCADE,
                invitee_email VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL CHECK (role IN ('reader', 'editor', 'manager')),
                token VARCHAR(255) NOT NULL UNIQUE,
                expires_at TIMESTAMPTZ NOT NULL
            );
        SQL);

        $this->execute('CREATE INDEX IF NOT EXISTS idx_group_parent_id ON "group"(parent_id);');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_group_owner_id ON "group"(owner_id);');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_note_owner_id ON note(owner_id);');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_permission_target ON permission(target_type, target_id);');
        $this->execute('CREATE INDEX IF NOT EXISTS idx_permission_grantee ON permission(grantee_type, grantee_id);');
    }

    public function down(): void
    {
        $this->execute('DROP TABLE IF EXISTS invitation;');
        $this->execute('DROP TABLE IF EXISTS permission;');
        $this->execute('DROP TABLE IF EXISTS user_group_member;');
        $this->execute('DROP TABLE IF EXISTS user_group;');
        $this->execute('DROP TABLE IF EXISTS note_tag;');
        $this->execute('DROP TABLE IF EXISTS tag;');
        $this->execute('DROP TABLE IF EXISTS note_group;');
        $this->execute('DROP TABLE IF EXISTS note;');
        $this->execute('DROP TABLE IF EXISTS "group";');
        $this->execute('DROP TABLE IF EXISTS "user";');
    }
}
