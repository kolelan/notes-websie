-- Общероссийский классификатор единиц измерения(ОКЕИ)
-- auto-generated definition
create table kls_okei
(
    kls_id      bigint      default public.nextval('kls.kls_kls_id_seq'::text) not null
        constraint kls_okei_pkey
            primary key,
    kls_is_del  boolean     default false                                      not null,
    qual_id     bigint                                                         not null
        constraint ck_kls_kls_okei__qual_code
            check (qual_id = kls.qual_id_by_qcode('KLS_OKEI'::character varying)),
    kls_namef   text                                                           not null,
    kls_names   text,
    kls_note    text,
    kls_code    text                                                           not null,
    kls_vers    integer     default 1                                          not null,
    kls_rubrika ltree                                                          not null,
    rus_name2   varchar(50) default NULL::character varying,
    eng_name1   varchar(50) default NULL::character varying,
    eng_name2   varchar(50) default NULL::character varying,
    visible     boolean     default true                                       not null
)
    inherits (kls)
    with (fillfactor = 95, autovacuum_enabled = true);

comment on table kls_okei is 'Общероссийский классификатор единиц измерения(ОКЕИ)';

comment on column kls_okei.kls_id is 'Код';

comment on column kls_okei.kls_is_del is 'Флаг удаления';

comment on column kls_okei.qual_id is 'Классификатор';

comment on column kls_okei.kls_namef is 'Наименование единицы измерения';

comment on column kls_okei.kls_names is 'Условное обозначение национальное';

comment on column kls_okei.kls_note is 'Описание раздела';

comment on column kls_okei.kls_code is 'Код единицы измерения';

comment on column kls_okei.kls_vers is 'Версия';

comment on column kls_okei.kls_rubrika is 'Рубрика';

comment on column kls_okei.rus_name2 is 'Кодовое буквенное обозначение национальное';

comment on column kls_okei.eng_name1 is 'Условное обозначение международное';

comment on column kls_okei.eng_name2 is 'Кодовое буквенное обозначение международное';

comment on column kls_okei.visible is 'Видимость';

alter table kls_okei
    owner to postgres;

create index idx_kls_kls_okei__eng_name1
    on kls_okei (eng_name1)
    where (NOT kls_is_del);

create index idx_kls_kls_okei__eng_name2
    on kls_okei (eng_name2)
    where (NOT kls_is_del);

create index idx_kls_kls_okei__pattern_eng_name1
    on kls_okei (eng_name1 varchar_pattern_ops)
    where (NOT kls_is_del);

create index idx_kls_kls_okei__pattern_eng_name2
    on kls_okei (eng_name2 varchar_pattern_ops)
    where (NOT kls_is_del);

create index idx_kls_kls_okei__pattern_rus_name2
    on kls_okei (rus_name2 varchar_pattern_ops)
    where (NOT kls_is_del);

create index idx_kls_kls_okei__rus_name2
    on kls_okei (rus_name2)
    where (NOT kls_is_del);

create index kls_okei_kls_code_idx
    on kls_okei (kls_code);

create unique index kls_okei_kls_id_idx
    on kls_okei (kls_id)
    where (kls_is_del = false);

create index kls_okei_kls_namef_idx
    on kls_okei using gin (kls_namef gin_trgm_ops);

create index kls_okei_kls_names_idx
    on kls_okei using gin (kls_names gin_trgm_ops);

create index kls_okei_kls_rubrika_idx
    on kls_okei using gist (kls_rubrika);

create index kls_okei_kls_rubrika_idx1
    on kls_okei (kls_rubrika);

create index kls_okei_qual_id_idx
    on kls_okei (qual_id);

create unique index kls_okei_qual_id_kls_code_idx
    on kls_okei (qual_id, kls_code)
    where (kls_is_del = false);

create index kls_okei_qual_id_nlevel_idx
    on kls_okei (qual_id, nlevel(kls_rubrika));

create index kls_okei_qual_id_subpath_idx
    on kls_okei (qual_id, subpath(kls_rubrika, 0, '-1'::integer));

grant select on kls_okei to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on kls_okei to "USER_KLS_SUID";
