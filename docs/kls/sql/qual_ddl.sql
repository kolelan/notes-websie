-- Разделы классификатора
-- auto-generated definition
create table qual
(
    qual_id       bigint  default public.nextval('kls.qual_qual_id_seq'::text) not null
        constraint pk_kls_qual__qual_id
            primary key,
    qual_is_del   boolean default false                                        not null,
    qual_vers     integer default 1                                            not null,
    qual_type_id  bigint                                                       not null,
    qual_namef    text                                                         not null,
    qual_names    text,
    qual_code     text,
    qual_note     text,
    tag           hstore,
    qual_date_beg date,
    qual_date_end date
)
    with (fillfactor = 90, autovacuum_enabled = true);

comment on table qual is 'Разделы классификатора';

comment on column qual.qual_id is 'Идентификатор раздела';

comment on column qual.qual_is_del is 'Флаг удаления';

comment on column qual.qual_vers is 'Версия';

comment on column qual.qual_type_id is 'Тип классификатора';

comment on column qual.qual_namef is 'Полное наименование классификатора';

comment on column qual.qual_names is 'Краткое наименование классификатора';

comment on column qual.qual_code is 'Код классификатора';

comment on column qual.qual_note is 'Описание классификатора';

comment on column qual.tag is 'Теги классификатора (hstore, необязательное поле)';

comment on column qual.qual_date_beg is 'Дата ввода в действие';

comment on column qual.qual_date_end is 'Дата окончания действия';

alter table qual
    owner to postgres;

create index idx_kls_qual__gin_qual_namef
    on qual using gin (qual_namef gin_trgm_ops);

create index idx_kls_qual__gin_qual_names
    on qual using gin (qual_names gin_trgm_ops);

create unique index idx_kls_qual__partial_qual_id
    on qual (qual_id)
    where (qual_is_del = false);

create index idx_kls_qual__qual_code
    on qual (qual_code);

create index idx_kls_qual__qual_type_id
    on qual (qual_type_id);

grant select on qual to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on qual to "USER_KLS_SUID";
