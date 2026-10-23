-- Inventario previo a la limpieza de «Profesionales y servicios» — SOLO LECTURA.
-- Correr ANTES de desplegar la migración 2027_01_21 (docs/marketplace-limpieza-profesionales.md).
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;

-- A. ¿Se aplicó 2027_01_20? Sin filas: nunca se desplegó; las consultas C a F fallan (las tablas no existen) y no hay nada que limpiar salvo los avisos y la auditoría (B, G, H).
SELECT migration, batch FROM migrations WHERE migration LIKE '2027\_01\_2%' ORDER BY migration;

-- Las 7 tablas de la función retirada, por nombre exacto. OJO: service_catalog_items (catálogo de servicios
-- de mantenimiento de cada empresa) es una función vigente y NO se toca; por eso nunca se filtra por «service_%».

-- B. Tablas y columna afectadas que existen hoy.
SELECT table_name, table_rows AS filas_aprox FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_name IN ('service_categories','service_profiles','service_profile_category','service_profile_terms','service_contact_requests','service_contact_messages','service_profile_reports') ORDER BY table_name;
SELECT column_name, column_type, column_default FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'suppliers' AND column_name = 'sells_products';

-- C. BLOQUEANTE: cuentas «solo servicios». Debe dar 0 filas; si no, la migración se detiene sola (decidir antes qué hacer con ellas).
SELECT id, name, status, country, tax_id IS NULL AS sin_identificacion, created_at FROM suppliers WHERE sells_products = 0;

-- D. Filas exactas por tabla (lo que se borra; queda en la copia de seguridad).
SELECT 'service_categories' AS tabla, COUNT(*) AS filas FROM service_categories
UNION ALL SELECT 'service_profiles', COUNT(*) FROM service_profiles
UNION ALL SELECT 'service_profile_category', COUNT(*) FROM service_profile_category
UNION ALL SELECT 'service_profile_terms', COUNT(*) FROM service_profile_terms
UNION ALL SELECT 'service_contact_requests', COUNT(*) FROM service_contact_requests
UNION ALL SELECT 'service_contact_messages', COUNT(*) FROM service_contact_messages
UNION ALL SELECT 'service_profile_reports', COUNT(*) FROM service_profile_reports;

-- E. Ninguna otra tabla referencia a esas 7 tablas (debe dar 0 filas).
SELECT table_name, constraint_name, referenced_table_name FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND referenced_table_name IN ('service_categories','service_profiles','service_profile_category','service_profile_terms','service_contact_requests','service_contact_messages','service_profile_reports') AND table_name NOT IN ('service_categories','service_profiles','service_profile_category','service_profile_terms','service_contact_requests','service_contact_messages','service_profile_reports');

-- F. Claves foráneas de esas 7 tablas hacia tablas que se conservan (desaparecen con ellas; no cambian esas tablas).
SELECT table_name, column_name, referenced_table_name FROM information_schema.key_column_usage
WHERE table_schema = DATABASE() AND table_name IN ('service_categories','service_profiles','service_profile_category','service_profile_terms','service_contact_requests','service_contact_messages','service_profile_reports') AND referenced_table_name IS NOT NULL AND referenced_table_name NOT IN ('service_categories','service_profiles','service_profile_category','service_profile_terms','service_contact_requests','service_contact_messages','service_profile_reports');

-- G. Avisos internos de la función retirada que se borran (claves exclusivas de esa función).
SELECT CASE WHEN dedupe_key LIKE 'mkt:team:svc-%' THEN 'equipo (revisión/reporte de perfil)' WHEN dedupe_key LIKE 'mkt:sr%' THEN 'empresa (respuesta a su solicitud)'
            WHEN dedupe_key LIKE 'mkt:c%' THEN 'empresa dueña de un perfil' ELSE 'proveedor dueño de un perfil' END AS destinatario, COUNT(*) AS avisos FROM notifications
WHERE type = 'App\\Notifications\\Marketplace\\MarketplaceNotice'
  AND (dedupe_key LIKE 'mkt:team:svc-%' OR dedupe_key LIKE 'mkt:s%:svc:%' OR dedupe_key LIKE 'mkt:c%:svc:%' OR dedupe_key LIKE 'mkt:sr%')
GROUP BY destinatario;
-- Control: avisos del marketplace que NO se tocan (deben seguir igual después).
SELECT COUNT(*) AS avisos_que_se_conservan FROM notifications
WHERE NOT (type = 'App\\Notifications\\Marketplace\\MarketplaceNotice'
  AND (dedupe_key LIKE 'mkt:team:svc-%' OR dedupe_key LIKE 'mkt:s%:svc:%' OR dedupe_key LIKE 'mkt:c%:svc:%' OR dedupe_key LIKE 'mkt:sr%'));

-- H. Auditoría de la función retirada que se borra; el resto se conserva.
SELECT action, COUNT(*) AS registros FROM platform_audit_logs WHERE action LIKE 'marketplace.service%' GROUP BY action;
SELECT COUNT(*) AS auditoria_que_se_conserva FROM platform_audit_logs WHERE action NOT LIKE 'marketplace.service%';

-- I. Totales comerciales (deben ser idénticos después de la limpieza).
SELECT 'suppliers' AS tabla, COUNT(*) AS filas FROM suppliers
UNION ALL SELECT 'supplier_products', COUNT(*) FROM supplier_products
UNION ALL SELECT 'supplier_product_photos', COUNT(*) FROM supplier_product_photos
UNION ALL SELECT 'marketplace_requests', COUNT(*) FROM marketplace_requests
UNION ALL SELECT 'marketplace_request_items', COUNT(*) FROM marketplace_request_items
UNION ALL SELECT 'marketplace_request_events', COUNT(*) FROM marketplace_request_events
UNION ALL SELECT 'featured_placements', COUNT(*) FROM featured_placements
UNION ALL SELECT 'supplier_payments', COUNT(*) FROM supplier_payments
UNION ALL SELECT 'supplier_subscriptions', COUNT(*) FROM supplier_subscriptions
UNION ALL SELECT 'companies', COUNT(*) FROM companies
UNION ALL SELECT 'users', COUNT(*) FROM users;
CHECKSUM TABLE supplier_products, supplier_product_photos, marketplace_requests, marketplace_request_items, marketplace_request_events,
               featured_placements, supplier_payments, supplier_subscriptions, marketplace_settings, companies, users;

ROLLBACK;
