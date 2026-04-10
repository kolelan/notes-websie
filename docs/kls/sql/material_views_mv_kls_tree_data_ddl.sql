
create materialized view mv_kls_tree_data as
SELECT kls_data.kls_id::integer              AS kls_id,
        kls_data.qual_id::integer             AS qual_id,
        kls_data.kls_rubrika,
       kp.kls_id::integer                    AS kls_id_parent,
        COALESCE(kp.kls_rubrika, NULL::ltree) AS kls_rubrika_parent,
       COALESCE(is_leaf.leaf, false)         AS leaf,
       kls_data.toid
FROM (SELECT k.kls_id,
             k.kls_is_del,
             q.qual_id,
             k.kls_rubrika,
             k.tableoid AS toid
      FROM kls.qual q
               JOIN kls.kls k ON k.kls_is_del = false AND k.qual_id = q.qual_id
      WHERE q.qual_is_del = false) kls_data
         LEFT JOIN (SELECT true AS leaf) is_leaf ON NOT (EXISTS(SELECT true AS bool
                                                                FROM kls.kls
                                                                WHERE NOT kls.kls_is_del
                                                                  AND kls.qual_id = kls_data.qual_id
                                                                  AND kls.kls_id <> kls_data.kls_id
                                                                  AND subpath(kls.kls_rubrika, 0, '-1'::integer) = kls_data.kls_rubrika
                                                                  AND kls_data.kls_rubrika @> kls.kls_rubrika
                                                                  AND kls.tableoid = kls_data.toid
                                                                LIMIT 1))
         LEFT JOIN LATERAL ( SELECT kp_1.kls_id,
                                    kp_1.kls_rubrika,
                                    kp_1.qual_id,
                                    kp_1.tableoid AS toid
                             FROM kls.kls kp_1
                             WHERE kp_1.kls_is_del = false
                               AND kp_1.qual_id = kls_data.qual_id
                               AND kp_1.kls_rubrika = subpath(kls_data.kls_rubrika, 0, '-1'::integer)
                               AND kp_1.kls_id <> kls_data.kls_id
                               AND nlevel(kls_data.kls_rubrika) > 1
                               AND kp_1.tableoid = kls_data.toid
    LIMIT 1) kp USING (qual_id, toid);

alter materialized view mv_kls_tree_data owner to postgres;

create index idx_kls_mv_kls_tree_data__qual_id
    on mv_kls_tree_data (qual_id);

create index idx_kls_mv_kls_tree_data__toid
    on mv_kls_tree_data (toid);

create unique index uidx_kls_mv_kls_tree_data__kls_id
    on mv_kls_tree_data (kls_id);

create index uidx_kls_mv_kls_tree_data__kls_id_parent
    on mv_kls_tree_data (kls_id_parent);

grant select on mv_kls_tree_data to "USER_KLS_S";

grant delete, insert, references, select, trigger, truncate, update on mv_kls_tree_data to "USER_KLS_SUID";
