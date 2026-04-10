create view v_qual_kls
            (qual_kls_id, qual_kls_is_del, qual_id_parent, kls_id_child, qual_namef_parent, qual_names_parent,
             qual_code_parent, qual_tag_parent, kls_namef_child, kls_names_child, kls_code_child, qual_id_child, qual_namef_child,
             qual_names_child, qual_code_child, qual_tag_child, kls_rubrika_child, qual_kls_vers)
as
SELECT qk.qual_kls_id,
       qk.qual_kls_is_del,
       qk.qual_id_parent,
       qk.kls_id_child,
       q.qual_namef  AS qual_namef_parent,
       q.qual_names  AS qual_names_parent,
       q.qual_code   AS qual_code_parent,
       q.tag         AS qual_tag_parent,
       c.kls_namef   AS kls_namef_child,
       c.kls_names   AS kls_names_child,
       c.kls_code    AS kls_code_child,
       c.qual_id     AS qual_id_child,
       qc.qual_namef AS qual_namef_child,
       qc.qual_names AS qual_names_child,
       qc.qual_code  AS qual_code_child,
       qc.tag        AS qual_tag_child,
       c.kls_rubrika AS kls_rubrika_child,
       qk.qual_kls_vers
FROM kls.qual_kls qk
         JOIN kls.qual q ON q.qual_id = qk.qual_id_parent AND q.qual_is_del = false
         JOIN kls.kls c ON c.kls_id = qk.kls_id_child AND c.kls_is_del = false
         JOIN kls.qual qc ON c.qual_id = qc.qual_id AND qc.qual_is_del = false;

alter table v_qual_kls
    owner to postgres;

grant select on v_qual_kls to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_qual_kls to "USER_KLS_SUID";

