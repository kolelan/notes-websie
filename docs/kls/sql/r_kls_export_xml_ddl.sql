create function kls_export_xml(p_qual_type_code_text text DEFAULT NULL::text, p_qual_code_text text DEFAULT NULL::text, p_is_need_file boolean DEFAULT false, p_out_dir_path text DEFAULT '/tmp'::text) returns text
    language plpgsql
as
$$
DECLARE
_xml_data 	TEXT	= '';
	_ba_last	TEXT	= (SELECT setting FROM pg_settings WHERE name = 'bytea_output');
	_filename	TEXT;
	_result 	TEXT;

BEGIN
	IF ( _ba_last !~* '^hex$'::TEXT) THEN
		SET bytea_output = 'hex';
END IF;

	IF (p_out_dir_path IS NULL) THEN
        p_out_dir_path := '/tmp'::TEXT;
END IF;

WITH kls_entry AS (
    SELECT
        xmlagg(
                xmlelement( name qual_type_entry,
                            xmlattributes(
                                    qt.qual_type_code		AS qual_type_code
                                , qt.qual_type_namef		AS qual_type_namef
                                , qt.qual_type_names		AS qual_type_names
                                , qt.qual_type_vers		AS qual_type_vers
                                )
                    , COALESCE(kls_qual.xml_data::TEXT, '<!--no_qual_entry-->'::TEXT)::XML
                    )
            ) :: TEXT AS xml_data
    FROM kls.qual_type qt
             JOIN LATERAL (
        SELECT
            xmlelement( name qual_entry,
                        xmlattributes(
                                kq1.qual_namef	AS qual_namef		--- text NOT NULL, -- Полное наименование классификатора
                            , kq1.qual_names	AS qual_names		--- text, -- Краткое наименование классификатора
                            , kq1.qual_code		AS qual_code		--- text, -- Код классификатора
                            , kq1.qual_note		AS qual_note		--- text, -- Описание классификатора
                            , kq1.qual_date_beg	AS qual_date_beg	--- date, -- Дата ввода в действие
                            , kq1.qual_date_end	AS qual_date_end	--- date, -- Дата окончания действия
                            , kq1.qual_vers		AS qual_vers		--- integer -- Версия
                            )
                , COALESCE(kl.xml_data :: TEXT, '<!--no_kls_entrys-->' :: TEXT)::XML
                ) :: TEXT AS xml_data
				, kq1.qual_type_id
             , qt1.qual_type_code
        FROM kls.qual kq1
                 JOIN kls.qual_type qt1 ON (qt1.qual_type_id = kq1.qual_type_id)
                 JOIN LATERAL (
            SELECT
                xmlelement(name kls_entrys,
                           xmlattributes(
                                   count(k.kls_id)	AS count_kls
                               )
                    , xmlagg(
                                   xmlelement( name kls_entry,
                                               xmlattributes(
                                                       k.kls_code       AS kls_code
                                                   , k.kls_namef      AS kls_namef
                                                   , k.kls_names      AS kls_names
                                                   , k.kls_note       AS kls_note
                                                   , k.kls_rubrika    AS kls_rubrika
                                                   , k.kls_vers       AS kls_vers
                                                   ))
                               )
                    ) :: TEXT AS xml_data
					, count(k.kls_id)	AS count_kls
                 , k.qual_id
                 , q.qual_code
            FROM kls.kls k
                     JOIN kls.qual q ON (q.qual_id = k.qual_id)
            WHERE (NOT k.kls_is_del)
            GROUP BY k.qual_id, q.qual_code
                ) kl ON (kl.qual_id = kq1.qual_id ) AND ( kl.qual_code  = CASE WHEN( p_qual_code_text IS NULL ) THEN kl.qual_code ELSE p_qual_code_text END )
        WHERE (NOT kq1.qual_is_del)
            ) kls_qual ON (kls_qual.qual_type_id = qt.qual_type_id) AND (kls_qual.qual_type_code = qt.qual_type_code )
    WHERE (NOT qt.qual_type_is_del)
      AND ( qt.qual_type_code  = CASE WHEN( p_qual_type_code_text IS NULL ) THEN qt.qual_type_code ELSE p_qual_type_code_text END )
    GROUP BY qt.qual_type_id
)
   , kls_export AS (
    SELECT
        xmlroot( xmlelement(name "kls.kls_export_xml", xmlelement(name export, xmlattributes(CURRENT_TIMESTAMP AS "datetime")), xmlagg(entry.xml_data::xml)), version '1.0', standalone yes) AS context_xml
    FROM kls_entry entry
) SELECT context_xml::TEXT FROM kls_export  INTO _xml_data;

_filename := replace((p_out_dir_path || '/kls_' || (COALESCE(p_qual_type_code_text::TEXT, 'all'::TEXT)::TEXT) || '_'::TEXT || (COALESCE(p_qual_code_text::TEXT, 'all'::TEXT)::TEXT)), '//', '/')||'_'||
		to_char(CURRENT_TIMESTAMP, 'YYYY-MM-DD_HH24-MI-SS'::TEXT)||'_exp.xml';

	IF (p_is_need_file) THEN
		EXECUTE format('COPY (SELECT %s AS xml_data) TO %L WITH (FORMAT text, ENCODING UTF8)', regexp_replace(''::TEXT || quote_literal(_xml_data)::TEXT, '[\n\r\t\b\f\v]+'::TEXT, ''::TEXT, 'g'), _filename);
_result := _filename;
ELSE
		_result := regexp_replace(_xml_data, '[\n\r\t\b\f\v]+'::TEXT, ''::TEXT, 'g');
END IF;

	IF ( _ba_last !~* '^hex$'::text) THEN
		EXECUTE format('SET bytea_output = %L;', _ba_last);
END IF;
	RETURN _result;
END;
$$;

alter function kls_export_xml(text, text, boolean, text) owner to postgres;

grant execute on function kls_export_xml(text, text, boolean, text) to "USER_SYS_S";

grant execute on function kls_export_xml(text, text, boolean, text) to "USER_SYS_SUID";

