-- Migration: add hstore tags to kls.qual and kls.kls
-- Safe for existing databases (idempotent)

BEGIN;

CREATE EXTENSION IF NOT EXISTS hstore;

ALTER TABLE IF EXISTS kls.qual
    ADD COLUMN IF NOT EXISTS tag hstore;

COMMENT ON COLUMN kls.qual.tag IS 'Теги классификатора (hstore, необязательное поле)';

ALTER TABLE IF EXISTS kls.kls
    ADD COLUMN IF NOT EXISTS tags hstore;

COMMENT ON COLUMN kls.kls.tags IS 'Теги раздела (hstore, необязательное поле)';

-- Keep view contracts aligned with updated DDL
CREATE OR REPLACE VIEW kls.v_qual
            (qual_id, qual_is_del, qual_type_id, qual_namef, qual_names, qual_code, qual_note, tag, qual_date_beg,
             qual_date_end, qual_type_names, qual_vers)
AS
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

CREATE OR REPLACE VIEW kls.v_kls
            (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_rubrika, kls_rubrika_parent,
             leaf, kls_id_parent, qual_code, qual_names, qual_namef, qual_tag, kls_vers, kls_code_parent, kls_namef_parent)
AS
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
       kp.kls_namef                           AS kls_namef_parent
FROM kls.kls k
         JOIN kls.qual q ON q.qual_is_del = false AND q.qual_id = k.qual_id
         LEFT JOIN kls.kls kp ON kp.kls_is_del = false AND kp.qual_id = k.qual_id AND
                                 kp.kls_rubrika = subpath(k.kls_rubrika, 0, '-1'::integer)
WHERE k.kls_is_del = false;

CREATE OR REPLACE VIEW kls.v_kls_atd
            (kls_id, kls_is_del, qual_id, kls_namef, kls_names, kls_note, tags, kls_code, kls_rubrika, kls_rubrika_parent,
             leaf, kls_id_parent, qual_code, qual_names, qual_namef, qual_tag, kls_vers, kls_code_parent, kls_namef_parent, way)
AS
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

CREATE OR REPLACE VIEW kls.v_qual_kls
            (qual_kls_id, qual_kls_is_del, qual_id_parent, kls_id_child, qual_namef_parent, qual_names_parent,
             qual_code_parent, qual_tag_parent, kls_namef_child, kls_names_child, kls_code_child, qual_id_child, qual_namef_child,
             qual_names_child, qual_code_child, qual_tag_child, kls_rubrika_child, qual_kls_vers)
AS
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

CREATE OR REPLACE VIEW kls.v_kls_kls
            (kls_kls_id, kls_kls_is_del, kls_id_parent, kls_id_child, kls_namef_parent, kls_names_parent,
             kls_code_parent, qual_id_parent, qual_namef_parent, qual_names_parent, qual_code_parent, qual_tag_parent, kls_namef_child,
             kls_names_child, kls_code_child, qual_id_child, qual_namef_child, qual_names_child, qual_code_child, qual_tag_child,
             kls_rubrika_parent, kls_rubrika_child, kls_kls_vers)
AS
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

CREATE OR REPLACE VIEW kls.v_kls_subject_okato
            (kls_id, kls_vers, qual_id, qual_code, qual_tag, kls_namef, kls_names, kls_code, kls_rubrika)
AS
SELECT ko.kls_id,
       ko.kls_vers,
       ko.qual_id,
       q.qual_code,
       q.tag AS qual_tag,
       ko.kls_namef,
       ko.kls_names,
       ko.kls_code,
       ko.kls_rubrika
FROM kls.kls ko
         JOIN kls.qual q ON q.qual_id = ko.qual_id AND NOT q.qual_is_del
WHERE true
  AND NOT ko.kls_is_del
  AND nlevel(ko.kls_rubrika) = 2
  AND q.qual_code = 'KLS_OKATO'::text
ORDER BY (ko.kls_rubrika::bigint[]);

COMMIT;
