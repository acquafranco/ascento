-- Auditoría del marketplace — SOLO LECTURA.
-- La sesión queda en modo solo lectura: cualquier escritura falla.
SET SESSION TRANSACTION READ ONLY;
START TRANSACTION READ ONLY;

-- 1. Migraciones del marketplace aplicadas
SELECT migration, batch FROM migrations WHERE migration LIKE '2027_01_1%' ORDER BY migration;

-- 2. Configuración comercial vigente
SELECT free_product_limit, premium_product_limit, premium_enabled, premium_price, pricing_currency,
       billing_countries, featured_enabled, featured_price_7, featured_price_30, over_limit_rule
FROM marketplace_settings;

-- 3. Totales por estado
SELECT 'proveedores' AS tabla, status, COUNT(*) AS n FROM suppliers GROUP BY status
UNION ALL SELECT 'productos', status, COUNT(*) FROM supplier_products GROUP BY status
UNION ALL SELECT CONCAT('destacados_', source), status, COUNT(*) FROM featured_placements GROUP BY source, status
UNION ALL SELECT CONCAT('cobros_', kind), status, COUNT(*) FROM supplier_payments GROUP BY kind, status
UNION ALL SELECT 'suscripciones', status, COUNT(*) FROM supplier_subscriptions GROUP BY status;

-- 4. Destacados activos de productos no visibles o de proveedores no habilitados
SELECT f.id AS destacado, f.source, f.starts_at, f.ends_at, f.status_reason,
       p.id AS producto, p.status AS estado_producto, p.hidden_by_admin,
       s.id AS proveedor, s.status AS estado_proveedor, s.closed_at
FROM featured_placements f
JOIN suppliers s ON s.id = f.supplier_id
LEFT JOIN supplier_products p ON p.id = f.supplier_product_id
WHERE f.status = 'active' AND f.ends_at > NOW()
  AND (p.id IS NULL OR p.status <> 'published' OR p.hidden_by_admin = 1 OR s.status <> 'approved' OR s.closed_at IS NOT NULL);

-- 5. Destacados activos superpuestos del mismo producto
SELECT a.supplier_product_id AS producto, a.id AS destacado_a, a.source AS origen_a, a.starts_at AS desde_a, a.ends_at AS hasta_a,
       b.id AS destacado_b, b.source AS origen_b, b.starts_at AS desde_b, b.ends_at AS hasta_b
FROM featured_placements a
JOIN featured_placements b ON b.supplier_product_id = a.supplier_product_id AND b.id > a.id
WHERE a.status = 'active' AND b.status = 'active' AND a.starts_at < b.ends_at AND b.starts_at < a.ends_at;

-- 6. featured_until del producto distinto del destacado vigente (copia desincronizada)
SELECT p.id AS producto, p.featured_until, x.hasta AS segun_historial
FROM supplier_products p
LEFT JOIN (SELECT supplier_product_id, MAX(ends_at) AS hasta FROM featured_placements
           WHERE status = 'active' AND starts_at <= NOW() AND ends_at > NOW() GROUP BY supplier_product_id) x
       ON x.supplier_product_id = p.id
WHERE NOT (p.featured_until <=> x.hasta) AND (p.featured_until > NOW() OR x.hasta IS NOT NULL);

-- 7. Compras de destacados con más de un pago acreditado (duplicados a devolver)
SELECT featured_placement_id AS destacado, COUNT(*) AS pagos, GROUP_CONCAT(mp_payment_id) AS pagos_mp, GROUP_CONCAT(status) AS estados
FROM supplier_payments WHERE kind = 'featured' AND paid_at IS NOT NULL
GROUP BY featured_placement_id HAVING COUNT(*) > 1;

-- 8. Pagos aprobados cuyo destacado no se activó, o destacados activos con el pago devuelto
SELECT pay.id AS cobro, pay.mp_payment_id, pay.status AS estado_cobro, pay.status_detail, f.id AS destacado, f.status AS estado_destacado, f.status_reason
FROM supplier_payments pay JOIN featured_placements f ON f.id = pay.featured_placement_id
WHERE (pay.status = 'approved' AND f.status NOT IN ('active', 'expired'))
   OR (pay.status = 'refunded' AND f.status = 'active');

-- 9. Premium vigente sin ningún cobro aprobado (no debería existir)
SELECT s.id, s.supplier_id, s.status, s.current_period_end
FROM supplier_subscriptions s
WHERE s.current_period_end > NOW()
  AND NOT EXISTS (SELECT 1 FROM supplier_payments p WHERE p.supplier_subscription_id = s.id AND p.paid_at IS NOT NULL);

-- 10. Proveedores con más publicaciones que su límite (lo corrige marketplace:tick; el catálogo ya muestra solo las que entran)
SELECT s.id AS proveedor, s.name, COUNT(p.id) AS publicadas,
       IF(sub.current_period_end > NOW() OR (sub.status = 'past_due' AND sub.current_period_end > NOW() - INTERVAL 5 DAY), 'premium', 'gratuito') AS plan
FROM suppliers s
JOIN supplier_products p ON p.supplier_id = s.id AND p.status = 'published' AND p.hidden_by_admin = 0
LEFT JOIN supplier_subscriptions sub ON sub.supplier_id = s.id
GROUP BY s.id, s.name, plan
HAVING publicadas > (SELECT IF(plan = 'premium', premium_product_limit, free_product_limit) FROM marketplace_settings LIMIT 1);

-- 11. Destacados que pasó al historial la migración 2027_01_19
SELECT id, supplier_product_id, starts_at, ends_at, status FROM featured_placements
WHERE status_reason = 'Destacado asignado antes del historial (migrado).';

-- 12. «Profesionales y servicios» (después de desplegar 2027_01_20): todas las cuentas existentes siguen vendiendo productos
SELECT sells_products, COUNT(*) AS proveedores FROM suppliers GROUP BY sells_products;
SELECT COUNT(*) AS especialidades FROM service_categories;
SELECT status, moderation, (approved_at IS NOT NULL) AS aprobado, COUNT(*) AS perfiles FROM service_profiles GROUP BY status, moderation, aprobado;

ROLLBACK;
