-- Общероссийский классификатор стран мира (ОКСМ)
-- auto-generated definition
create table kls_oksm
(
    kls_id      bigint     default public.nextval('kls.kls_kls_id_seq'::text) not null
        constraint kls_oksm_pkey
            primary key,
    kls_is_del  boolean    default false                                      not null,
    qual_id     bigint                                                        not null
        constraint fk_kls_kls_oksm__qual_id
            references qual
            on update cascade on delete cascade
            deferrable initially deferred
        constraint ck_kls_kls_oksm__qual_code
            check (qual_id = kls.qual_id_by_qcode('KLS_OKSM'::character varying)),
    kls_namef   text                                                          not null,
    kls_names   text,
    kls_note    text,
    kls_code    text                                                          not null,
    kls_vers    integer    default 1                                          not null,
    kls_rubrika ltree                                                         not null,
    alfa2       varchar(2) default NULL::character varying,
    number_code varchar(4)                                                    not null,
    visible     boolean    default true                                       not null
)
    inherits (kls)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table kls_oksm is 'Общероссийский классификатор стран мира (ОКСМ)';

comment on column kls_oksm.kls_id is 'Код';

comment on column kls_oksm.kls_is_del is 'Флаг удаления';

comment on column kls_oksm.qual_id is 'Классификатор';

comment on column kls_oksm.kls_namef is 'Полное наименование страны';

comment on column kls_oksm.kls_names is 'Наименование страны';

comment on column kls_oksm.kls_note is 'Описание раздела';

comment on column kls_oksm.kls_code is 'Код альфа-3';

comment on column kls_oksm.kls_vers is 'Версия';

comment on column kls_oksm.kls_rubrika is 'Рубрика';

comment on column kls_oksm.alfa2 is 'Буквенный код альфа-2 страны';

comment on column kls_oksm.number_code is 'Цифровой код страны';

comment on column kls_oksm.visible is 'Видимость';

alter table kls_oksm
    owner to postgres;

create unique index idx_kls_kls_oksm__alfa2
    on kls_oksm (alfa2)
    where (NOT kls_is_del);

create unique index idx_kls_kls_oksm__number_code
    on kls_oksm (number_code)
    where (NOT kls_is_del);

create index kls_oksm_kls_code_idx
    on kls_oksm (kls_code);

create unique index kls_oksm_kls_id_idx
    on kls_oksm (kls_id)
    where (kls_is_del = false);

create index kls_oksm_kls_namef_idx
    on kls_oksm using gin (kls_namef gin_trgm_ops);

create index kls_oksm_kls_names_idx
    on kls_oksm using gin (kls_names gin_trgm_ops);

create index kls_oksm_kls_rubrika_idx
    on kls_oksm using gist (kls_rubrika);

create index kls_oksm_kls_rubrika_idx1
    on kls_oksm (kls_rubrika);

create index kls_oksm_qual_id_idx
    on kls_oksm (qual_id);

create unique index kls_oksm_qual_id_kls_code_idx
    on kls_oksm (qual_id, kls_code)
    where (kls_is_del = false);

create index kls_oksm_qual_id_nlevel_idx
    on kls_oksm (qual_id, nlevel(kls_rubrika));

create index kls_oksm_qual_id_subpath_idx
    on kls_oksm (qual_id, subpath(kls_rubrika, 0, '-1'::integer));

grant select on kls_oksm to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls_oksm to "USER_KLS_SUID";
