create view v_kls_atd
            (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_rubrika, kls_rubrika_parent,
             leaf, kls_id_parent, qual_code, qual_names, qual_namef, qual_tag, kls_vers, kls_code_parent, kls_namef_parent, way)
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
       kp.kls_namef                           AS kls_namef_parent,
       NULL::text                             AS way
FROM kls.kls k
         JOIN kls.qual q ON q.qual_is_del = false AND q.qual_id = k.qual_id
         LEFT JOIN kls.kls kp ON kp.kls_is_del = false AND kp.qual_id = k.qual_id AND
                                 kp.kls_rubrika = subpath(k.kls_rubrika, 0, '-1'::integer)
WHERE k.kls_is_del = false
  AND (k.qual_id = ANY (ARRAY [446::bigint, 447::bigint, 448::bigint]));

comment on view v_kls_atd is 'Разделы классификатора';

comment on column v_kls_atd.kls_id is 'Код';

comment on column v_kls_atd.kls_is_del is 'Флаг удаления';

comment on column v_kls_atd.qual_id is 'Классификатор';

comment on column v_kls_atd.kls_namef is 'Полное наименование раздела';

comment on column v_kls_atd.kls_names is 'Краткое наименование раздела';

comment on column v_kls_atd.kls_note is 'Описание раздела';

comment on column v_kls_atd.tags is 'Теги раздела (hstore, необязательное поле)';

comment on column v_kls_atd.kls_code is 'Код раздела';

comment on column v_kls_atd.kls_rubrika is 'Рубрика';

comment on column v_kls_atd.kls_rubrika_parent is 'Рубрика родительского элемента';

comment on column v_kls_atd.leaf is 'Признак конечного элемента древовидной структуры';

comment on column v_kls_atd.kls_id_parent is 'Идентификатор родительского элемента';

comment on column v_kls_atd.kls_vers is 'Версия';

comment on column v_kls_atd.kls_code_parent is 'Код раздела родительского элемента';

comment on column v_kls_atd.kls_namef_parent is 'Полное наименование раздела родительского элемента';

comment on column v_kls_atd.qual_tag is 'Теги классификатора (hstore, необязательное поле)';

alter table v_kls_atd
    owner to postgres;

grant select on v_kls_atd to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_kls_atd to "USER_KLS_SUID";

