create view v_qual
            (qual_id, qual_is_del, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag, qual_date_beg,
             qual_date_end, qual_type_names, qual_vers)
as
SELECT q.qual_id,
       q.qual_is_del,
       q.qual_type_id,
       q.qual_namef,
       q.qual_names,
       q.qual_code,
       q.qual_note,
       q.tag,
       q.qual_date_beg,
       q.qual_date_end,
       NULL::text AS qual_type_names,
       q.qual_vers
FROM kls.qual q
WHERE q.qual_is_del = false;

alter table v_qual
    owner to postgres;

grant select on v_qual to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_qual to "USER_KLS_SUID";

