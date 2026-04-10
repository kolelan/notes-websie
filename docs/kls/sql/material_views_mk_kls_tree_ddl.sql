
create materialized view mv_kls_tree as
WITH RECURSIVE data_tree AS (
    SELECT root.kls_id,
           root.qual_id,
           root.kls_rubrika,
           root.kls_id_parent,
           root.kls_rubrika_parent,
           intset(root.kls_id) AS arr_kls_id,
           root.leaf,
           root.toid
    FROM kls.mv_kls_tree_data root
    WHERE root.kls_id_parent IS NULL
    UNION ALL
    SELECT child.kls_id,
           child.qual_id,
           child.kls_rubrika,
           child.kls_id_parent,
           child.kls_rubrika_parent,
           parent.arr_kls_id | child.kls_id AS arr_kls_id,
           child.leaf,
           child.toid
    FROM data_tree parent
             JOIN kls.mv_kls_tree_data child ON child.qual_id = parent.qual_id AND child.toid = parent.toid AND
                                                parent.kls_id = child.kls_id_parent AND
                                                (parent.arr_kls_id # child.kls_id) = 0
    WHERE parent.leaf = false
)
SELECT td.kls_id,
       td.qual_id,
       td.kls_rubrika,
       td.kls_id_parent,
       td.kls_rubrika_parent,
       td.arr_kls_id,
       td.leaf,
       td.toid,
       k.kls_vers,
       k.kls_code,
       k.kls_names,
       k.kls_namef,
       k.kls_note,
       kp.kls_code_parent,
       kp.kls_names_parent,
       kp.kls_namef_parent
FROM data_tree td
         JOIN LATERAL ( SELECT k_1.kls_id,
                               k_1.kls_vers,
                               k_1.kls_code,
                               k_1.kls_names,
                               k_1.kls_namef,
                               k_1.kls_note
                        FROM kls.kls k_1
                        WHERE k_1.kls_id = td.kls_id
                          AND k_1.qual_id = td.qual_id
                          AND k_1.tableoid = td.toid
    LIMIT 1) k USING (kls_id)
         LEFT JOIN LATERAL ( SELECT kp_1.kls_id    AS kls_id_parent,
    kp_1.kls_code  AS kls_code_parent,
    kp_1.kls_names AS kls_names_parent,
    kp_1.kls_namef AS kls_namef_parent
FROM kls.kls kp_1
WHERE kp_1.kls_id = td.kls_id_parent
  AND kp_1.qual_id = td.qual_id
  AND kp_1.tableoid = td.toid
    LIMIT 1) kp USING (kls_id_parent);

alter materialized view mv_kls_tree owner to postgres;

create index idx_kls_mv_kls_tree__arr_kls_id
    on mv_kls_tree using gin (arr_kls_id gin__int_ops);

create index idx_kls_mv_kls_tree__qual_id
    on mv_kls_tree (qual_id);

create index idx_kls_mv_kls_tree__toid
    on mv_kls_tree (toid);

create unique index uidx_kls_mv_kls_tree__kls_id
    on mv_kls_tree (kls_id);

create index uidx_kls_mv_kls_tree__kls_id_parent
    on mv_kls_tree (kls_id_parent);

grant select on mv_kls_tree to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on mv_kls_tree to "USER_KLS_SUID";
