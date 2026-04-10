-- Разделы классификатора
-- auto-generated definition
create table kls
(
    kls_id      bigint  default public.nextval('kls.kls_kls_id_seq'::text) not null
        constraint pk_kls_kls__kls_id
            primary key,
    kls_is_del  boolean default false                                      not null,
    qual_id     bigint                                                     not null
        constraint fk_kls_kls__qual_id
            references qual
            on update cascade on delete cascade
            deferrable initially deferred,
    kls_namef   text                                                       not null,
    kls_names   text,
    kls_note    text,
    tags        hstore,
    kls_code    text                                                       not null,
    kls_vers    integer default 1                                          not null,
    kls_rubrika ltree                                                      not null
)
    with (fillfactor = 90, autovacuum_enabled = true);

comment on table kls is 'Разделы классификатора';

comment on column kls.kls_id is 'Код';

comment on column kls.kls_is_del is 'Флаг удаления';

comment on column kls.qual_id is 'Классификатор';

comment on column kls.kls_namef is 'Полное наименование раздела';

comment on column kls.kls_names is 'Краткое наименование раздела';

comment on column kls.kls_note is 'Описание раздела';

comment on column kls.tags is 'Теги раздела (hstore, необязательное поле)';

comment on column kls.kls_code is 'Код раздела';

comment on column kls.kls_vers is 'Версия';

comment on column kls.kls_rubrika is 'Рубрика';

alter table kls
    owner to postgres;

create index idx_kls_kls__code
    on kls (kls_code);

create index idx_kls_kls__gist_rubrika
    on kls using gist (kls_rubrika);

create index idx_kls_kls__namef_trgm
    on kls using gin (kls_namef gin_trgm_ops);

create index idx_kls_kls__names_trgm
    on kls using gin (kls_names gin_trgm_ops);

create unique index idx_kls_kls__partial_kls_id
    on kls (kls_id)
    where (kls_is_del = false);

create index idx_kls_kls__qual_id
    on kls (qual_id);

create index idx_kls_kls__qual_id_kls_rubrika
    on kls (qual_id, subpath(kls_rubrika, 0, '-1'::integer));

create index idx_kls_kls__qual_id_nlevel
    on kls (qual_id, nlevel(kls_rubrika));

create index idx_kls_kls__rubrika
    on kls (kls_rubrika);

create unique index uidx_kls_kls__qual_id_kls_code
    on kls (qual_id, kls_code)
    where (kls_is_del = false);

grant select on kls to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls to "USER_KLS_SUID";
