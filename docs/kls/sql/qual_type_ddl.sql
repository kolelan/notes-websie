-- Типы классификаторов
-- auto-generated definition
create table qual_type
(
    qual_type_id     bigint  default public.nextval('kls.qual_type_qual_type_id_seq'::text) not null
        constraint pk_kls_qual_type__qual_type_id
            primary key,
    qual_type_is_del boolean default false                                                  not null,
    qual_type_namef  text                                                                   not null,
    qual_type_names  text,
    qual_type_code   text,
    qual_type_vers   integer default 1                                                      not null
)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table qual_type is 'Типы классификаторов';

comment on column qual_type.qual_type_id is 'Ключ';

comment on column qual_type.qual_type_is_del is 'Признак удаления';

comment on column qual_type.qual_type_namef is 'Наименование полное';

comment on column qual_type.qual_type_names is 'Наименование сокращенное';

comment on column qual_type.qual_type_code is 'Код типа классификатора';

comment on column qual_type.qual_type_vers is 'Версия';

alter table qual_type
    owner to postgres;

create index idx_kls_qual_type__gin_qual_type_namef
    on qual_type using gin (qual_type_namef gin_trgm_ops);

create index idx_kls_qual_type__gin_qual_type_names
    on qual_type using gin (qual_type_names gin_trgm_ops);

create unique index idx_kls_qual_type__partial_qual_type_id
    on qual_type (qual_type_id)
    where (qual_type_is_del = false);

create index idx_kls_qual_type__qual_type_code
    on qual_type (qual_type_code);

grant select on qual_type to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on qual_type to "USER_KLS_SUID";
