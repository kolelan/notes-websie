<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddKlsHstoreTags extends AbstractMigration
{
    public function up(): void
    {
        // hstore is required for kls.qual.tag and kls.kls.tags
        $this->execute('CREATE EXTENSION IF NOT EXISTS hstore;');

        $this->execute(<<<SQL
            DO $$
            BEGIN
                IF to_regclass('kls.qual') IS NOT NULL THEN
                    ALTER TABLE kls.qual
                        ADD COLUMN IF NOT EXISTS tag hstore;
                    COMMENT ON COLUMN kls.qual.tag IS 'Теги классификатора (hstore, необязательное поле)';
                END IF;
            END
            $$;
        SQL);

        $this->execute(<<<SQL
            DO $$
            BEGIN
                IF to_regclass('kls.kls') IS NOT NULL THEN
                    ALTER TABLE kls.kls
                        ADD COLUMN IF NOT EXISTS tags hstore;
                    COMMENT ON COLUMN kls.kls.tags IS 'Теги раздела (hstore, необязательное поле)';
                END IF;
            END
            $$;
        SQL);
    }

    public function down(): void
    {
        $this->execute(<<<SQL
            DO $$
            BEGIN
                IF to_regclass('kls.kls') IS NOT NULL THEN
                    ALTER TABLE kls.kls
                        DROP COLUMN IF EXISTS tags;
                END IF;
            END
            $$;
        SQL);

        $this->execute(<<<SQL
            DO $$
            BEGIN
                IF to_regclass('kls.qual') IS NOT NULL THEN
                    ALTER TABLE kls.qual
                        DROP COLUMN IF EXISTS tag;
                END IF;
            END
            $$;
        SQL);
    }
}

