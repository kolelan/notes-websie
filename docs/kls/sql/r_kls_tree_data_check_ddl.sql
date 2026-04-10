create function kls_tree_data_check() returns boolean
    language plpgsql
as
$$
BEGIN
  IF NOT EXISTS (
    SELECT true FROM pg_catalog.pg_class pc
    WHERE pc.relkind = 'm'::char
    AND pc.relname = 'mv_kls_tree_data'
    AND pc.relnamespace IN (
      SELECT oid FROM pg_catalog.pg_namespace
      WHERE nspname = 'kls'
      LIMIT 1
    )
  ) THEN
    --DROP MATERIALIZED VIEW IF EXISTS kls.mv_kls_tree_data CASCADE;
    CREATE MATERIALIZED VIEW kls.mv_kls_tree_data AS
SELECT
    kls_data.kls_id::int4 AS kls_id
      , kls_data.qual_id::int4 AS qual_id
      , kls_data.kls_rubrika
     , kp.kls_id::int4	AS kls_id_parent
      , COALESCE ( kp.kls_rubrika, NULL :: ltree) 	AS kls_rubrika_parent
     , COALESCE ( is_leaf.leaf, FALSE::bool)		AS leaf
     , kls_data.toid
FROM (
         SELECT
             k.kls_id
              , k.kls_is_del
              , q.qual_id
              , k.kls_rubrika
              , k.tableoid AS toid
         FROM kls.qual q
                  JOIN kls.kls k ON k.kls_is_del = FALSE
             AND k.qual_id = q.qual_id
         WHERE q.qual_is_del = FALSE
     ) kls_data
         LEFT JOIN (SELECT true AS leaf ) is_leaf
                   ON NOT EXISTS (
                           SELECT TRUE
                           FROM kls.kls
                           WHERE NOT kls.kls_is_del
                             AND kls.qual_id = kls_data.qual_id
                             AND kls.kls_id <> kls_data.kls_id
                             AND subpath ( kls.kls_rubrika, 0, ( -1 ) ) = kls_data.kls_rubrika
                             AND kls_data.kls_rubrika @> kls.kls_rubrika
                             AND kls.tableoid = kls_data.toid
                           LIMIT 1
                       )
         LEFT JOIN LATERAL(
    SELECT
        kp.kls_id
         , kp.kls_rubrika
         , kp.qual_id
         , kp.tableoid AS toid
    FROM kls.kls kp
    WHERE kp.kls_is_del = FALSE
      AND kp.qual_id = kls_data.qual_id
      AND kp.kls_rubrika = subpath ( kls_data.kls_rubrika, 0, ( -1 ) )
      --AND kp.kls_rubrika @> kls_data.kls_rubrika
      AND kp.kls_id <> kls_data.kls_id
      AND nlevel ( kls_data.kls_rubrika ) > 1
      AND kp.tableoid = kls_data.toid
        LIMIT 1
    ) kp USING(qual_id, toid);

CREATE UNIQUE INDEX uidx_kls_mv_kls_tree_data__kls_id
    ON kls.mv_kls_tree_data
    USING BTREE (kls_id);

CREATE INDEX uidx_kls_mv_kls_tree_data__kls_id_parent
    ON kls.mv_kls_tree_data
    USING BTREE (kls_id_parent);

CREATE INDEX idx_kls_mv_kls_tree_data__qual_id
    ON kls.mv_kls_tree_data
    USING BTREE (qual_id);

CREATE INDEX idx_kls_mv_kls_tree_data__toid
    ON kls.mv_kls_tree_data
    USING BTREE (toid);

PERFORM 1;
ELSE
    REFRESH MATERIALIZED VIEW kls.mv_kls_tree_data;
END IF;

  IF NOT EXISTS (
    SELECT true FROM pg_catalog.pg_class pc
    WHERE pc.relkind = 'm'::char
    AND pc.relname = 'mv_kls_tree'
    AND pc.relnamespace IN (
      SELECT oid FROM pg_catalog.pg_namespace
      WHERE nspname = 'kls'
      LIMIT 1
    )
  ) THEN
    -- DROP MATERIALIZED VIEW IF EXISTS kls.mv_kls_tree CASCADE;
    CREATE MATERIALIZED VIEW kls.mv_kls_tree AS
    WITH RECURSIVE data_tree AS
    (
      SELECT
          root.kls_id
        , root.qual_id
        , root.kls_rubrika
        , root.kls_id_parent
        , root.kls_rubrika_parent
        , intset(root.kls_id) AS arr_kls_id
        , root.leaf
        , root.toid
      FROM kls.mv_kls_tree_data root
      WHERE kls_id_parent IS NULL
      UNION ALL
      SELECT
          child.kls_id
        , child.qual_id
        , child.kls_rubrika
        , child.kls_id_parent
        , child.kls_rubrika_parent
        , parent.arr_kls_id | child.kls_id::int4 AS arr_kls_id
        , child.leaf
        , child.toid
      FROM data_tree parent
      JOIN kls.mv_kls_tree_data child ON
        child.qual_id = parent.qual_id
        AND child.toid = parent.toid
        AND parent.kls_id = child.kls_id_parent
        AND (parent.arr_kls_id # child.kls_id)=0
      WHERE parent.leaf=false
    )

SELECT
    td.*
     , k.kls_vers
     , k.kls_code
     , k.kls_names
     , k.kls_namef
     , k.kls_note
     , kp.kls_code_parent
     , kp.kls_names_parent
     , kp.kls_namef_parent
FROM data_tree td
         JOIN LATERAL(
    SELECT
        k.kls_id
         , k.kls_vers
         , k.kls_code
         , k.kls_names
         , k.kls_namef
         , k.kls_note
    FROM kls.kls k
    WHERE k.kls_id = td.kls_id
      AND k.qual_id = td.qual_id
      AND k.TABLEOID = td.toid
        LIMIT 1
    ) k USING(kls_id)
    LEFT JOIN LATERAL (
SELECT
    kp.kls_id AS kls_id_parent
        , kp.kls_code AS kls_code_parent
        , kp.kls_names AS kls_names_parent
        , kp.kls_namef AS kls_namef_parent
FROM kls.kls kp
WHERE kp.kls_id = td.kls_id_parent
  AND kp.qual_id = td.qual_id
  AND kp.TABLEOID = td.toid
    LIMIT 1
    ) kp USING(kls_id_parent);

CREATE UNIQUE INDEX uidx_kls_mv_kls_tree__kls_id
    ON kls.mv_kls_tree
    USING BTREE (kls_id);

CREATE INDEX uidx_kls_mv_kls_tree__kls_id_parent
    ON kls.mv_kls_tree
    USING BTREE (kls_id_parent);

CREATE INDEX idx_kls_mv_kls_tree__qual_id
    ON kls.mv_kls_tree
    USING BTREE (qual_id);

CREATE INDEX idx_kls_mv_kls_tree__toid
    ON kls.mv_kls_tree
    USING BTREE (toid);

CREATE INDEX idx_kls_mv_kls_tree__arr_kls_id
    ON kls.mv_kls_tree
    USING GIN (arr_kls_id gin__int_ops);

PERFORM 1;
ELSE
    REFRESH MATERIALIZED VIEW kls.mv_kls_tree;
END IF;

return true;
END;
$$;

comment on function kls_tree_data_check() is 'Проверка существования материализованного представления дерева классификатора и его создания';

alter function kls_tree_data_check() owner to postgres;

grant execute on function kls_tree_data_check() to "USER_KLS_S";

grant execute on function kls_tree_data_check() to "USER_KLS_SUID";

