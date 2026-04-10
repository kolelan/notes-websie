create view v_kls_kls
            (kls_kls_id, kls_kls_is_del, kls_id_parent, kls_id_child, kls_namef_parent, kls_names_parent,
             kls_code_parent, qual_id_parent, qual_namef_parent, qual_names_parent, qual_code_parent, qual_tag_parent, kls_namef_child,
             kls_names_child, kls_code_child, qual_id_child, qual_namef_child, qual_names_child, qual_code_child, qual_tag_child,
             kls_rubrika_parent, kls_rubrika_child, kls_kls_vers)
as
SELECT kk.kls_kls_id,
       kk.kls_kls_is_del,
       kk.kls_id_parent,
       kk.kls_id_child,
       p.kls_namef   AS kls_namef_parent,
       p.kls_names   AS kls_names_parent,
       p.kls_code    AS kls_code_parent,
       p.qual_id     AS qual_id_parent,
       qp.qual_namef AS qual_namef_parent,
       qp.qual_names AS qual_names_parent,
       qp.qual_code  AS qual_code_parent,
       qp.tag        AS qual_tag_parent,
       c.kls_namef   AS kls_namef_child,
       c.kls_names   AS kls_names_child,
       c.kls_code    AS kls_code_child,
       c.qual_id     AS qual_id_child,
       qc.qual_namef AS qual_namef_child,
       qc.qual_names AS qual_names_child,
       qc.qual_code  AS qual_code_child,
       qc.tag        AS qual_tag_child,
       p.kls_rubrika AS kls_rubrika_parent,
       c.kls_rubrika AS kls_rubrika_child,
       kk.kls_kls_vers
FROM (SELECT kls_kls.kls_kls_id,
             kls_kls.kls_kls_vers,
             kls_kls.kls_kls_is_del,
             unnest(ARRAY [kls_kls.kls_id_parent, kls_kls.kls_id_child]) AS kls_id_parent,
             unnest(ARRAY [kls_kls.kls_id_child, kls_kls.kls_id_parent]) AS kls_id_child
      FROM kls.kls_kls
      WHERE kls_kls.kls_kls_is_del = false) kk
         JOIN kls.kls p ON p.kls_id = kk.kls_id_parent AND p.kls_is_del = false
         JOIN kls.kls c ON c.kls_id = kk.kls_id_child AND c.kls_is_del = false
         JOIN kls.qual qp ON p.qual_id = qp.qual_id AND qp.qual_is_del = false
         JOIN kls.qual qc ON c.qual_id = qc.qual_id AND qc.qual_is_del = false;

alter table v_kls_kls
    owner to postgres;

grant select on v_kls_kls to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on v_kls_kls to "USER_KLS_SUID";

