<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddKlsHstoreTags extends AbstractMigration
{
    public function up(): void
    {
        // Core extensions and schema for KLS subsystem
        $this->execute('CREATE EXTENSION IF NOT EXISTS pg_trgm;');
        $this->execute('CREATE EXTENSION IF NOT EXISTS ltree;');
        $this->execute('CREATE EXTENSION IF NOT EXISTS hstore;');
        $this->execute('CREATE SCHEMA IF NOT EXISTS kls;');

        $this->execute(<<<SQL
            CREATE SEQUENCE IF NOT EXISTS kls.qual_type_qual_type_id_seq;
            CREATE SEQUENCE IF NOT EXISTS kls.qual_qual_id_seq;
            CREATE SEQUENCE IF NOT EXISTS kls.kls_kls_id_seq;
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS kls.qual_type
            (
                qual_type_id     bigint  default nextval('kls.qual_type_qual_type_id_seq'::regclass) not null
                    constraint pk_kls_qual_type__qual_type_id
                        primary key,
                qual_type_is_del boolean default false                                                  not null,
                qual_type_namef  text                                                                   not null,
                qual_type_names  text,
                qual_type_code   text,
                qual_type_vers   integer default 1                                                      not null
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS kls.qual
            (
                qual_id       bigint  default nextval('kls.qual_qual_id_seq'::regclass) not null
                    constraint pk_kls_qual__qual_id
                        primary key,
                qual_is_del   boolean default false                                        not null,
                qual_vers     integer default 1                                            not null,
                qual_type_id  bigint                                                       not null
                    constraint fk_kls_qual__qual_type_id
                        references kls.qual_type
                        deferrable initially deferred,
                qual_namef    text                                                         not null,
                qual_names    text,
                qual_code     text,
                qual_note     text,
                tag           hstore,
                qual_date_beg date,
                qual_date_end date
            );
        SQL);

        $this->execute(<<<SQL
            CREATE TABLE IF NOT EXISTS kls.kls
            (
                kls_id      bigint  default nextval('kls.kls_kls_id_seq'::regclass) not null
                    constraint pk_kls_kls__kls_id
                        primary key,
                kls_is_del  boolean default false                                      not null,
                qual_id     bigint                                                     not null
                    constraint fk_kls_kls__qual_id
                        references kls.qual
                        on update cascade on delete cascade
                        deferrable initially deferred,
                kls_namef   text                                                       not null,
                kls_names   text,
                kls_note    text,
                tags        hstore,
                kls_code    text                                                       not null,
                kls_vers    integer default 1                                          not null,
                kls_rubrika ltree                                                      not null
            );
        SQL);

        $this->execute(<<<SQL
            CREATE INDEX IF NOT EXISTS idx_kls_qual_type__qual_type_code ON kls.qual_type (qual_type_code);
            CREATE INDEX IF NOT EXISTS idx_kls_qual__qual_type_id ON kls.qual (qual_type_id);
            CREATE INDEX IF NOT EXISTS idx_kls_qual__qual_code ON kls.qual (qual_code);
            CREATE INDEX IF NOT EXISTS idx_kls_kls__qual_id ON kls.kls (qual_id);
            CREATE INDEX IF NOT EXISTS idx_kls_kls__code ON kls.kls (kls_code);
            CREATE INDEX IF NOT EXISTS idx_kls_kls__gist_rubrika ON kls.kls USING gist (kls_rubrika);
        SQL);

        $this->execute(<<<SQL
            INSERT INTO kls.qual_type (qual_type_is_del, qual_type_vers, qual_type_namef, qual_type_names, qual_type_code)
            SELECT FALSE, 1, 'Базовый тип', 'Базовый', 'BASE'
            WHERE NOT EXISTS (SELECT 1 FROM kls.qual_type);
        SQL);

        $this->execute(<<<SQL
            CREATE OR REPLACE VIEW kls.v_kls
                        (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_rubrika, kls_rubrika_parent,
                         leaf, kls_id_parent, qual_code, qual_names, qual_namef, qual_tag, kls_vers, kls_code_parent, kls_namef_parent)
            as
            SELECT k.kls_id,
                   k.kls_is_del,
                   q.qual_id,
                   k.kls_namef,
                   k.kls_names,
                   k.kls_note,
                   k.tags,
                   k.kls_code,
                   k.kls_rubrika,
                   COALESCE(kp.kls_rubrika, NULL::ltree)  AS kls_rubrika_parent,
                   NOT (EXISTS(SELECT kls.kls_id
                               FROM kls.kls
                               WHERE kls.qual_id = k.qual_id
                                 AND subpath(kls.kls_rubrika, 0, '-1'::integer) = k.kls_rubrika
                                 AND NOT kls.kls_is_del)) AS leaf,
                   kp.kls_id                              AS kls_id_parent,
                   q.qual_code,
                   q.qual_names,
                   q.qual_namef,
                   q.tag                                   AS qual_tag,
                   k.kls_vers,
                   kp.kls_code                            AS kls_code_parent,
                   kp.kls_namef                           AS kls_namef_parent
            FROM kls.kls k
                     JOIN kls.qual q ON q.qual_is_del = false AND q.qual_id = k.qual_id
                     LEFT JOIN kls.kls kp ON kp.kls_is_del = false AND kp.qual_id = k.qual_id AND
                                             kp.kls_rubrika = subpath(k.kls_rubrika, 0, '-1'::integer)
            WHERE k.kls_is_del = false;
        SQL);
    }

    public function down(): void
    {
        $this->execute('DROP VIEW IF EXISTS kls.v_kls;');
        $this->execute('DROP TABLE IF EXISTS kls.kls;');
        $this->execute('DROP TABLE IF EXISTS kls.qual;');
        $this->execute('DROP TABLE IF EXISTS kls.qual_type;');
        $this->execute('DROP SEQUENCE IF EXISTS kls.kls_kls_id_seq;');
        $this->execute('DROP SEQUENCE IF EXISTS kls.qual_qual_id_seq;');
        $this->execute('DROP SEQUENCE IF EXISTS kls.qual_type_qual_type_id_seq;');
        $this->execute('DROP SCHEMA IF EXISTS kls;');
    }
}

