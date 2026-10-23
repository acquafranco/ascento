#!/usr/bin/env bash
# Copia de seguridad de lo que borra la migración 2027_01_21 («Profesionales y servicios»).
# Solo LEE la base (mysqldump --single-transaction). Ver docs/marketplace-limpieza-profesionales.md.
#
# Uso:  bash docs/sql/limpieza-profesionales-backup.sh <base> <carpeta-destino> [opciones de conexión de mysql...]
# Ej.:  bash docs/sql/limpieza-profesionales-backup.sh ascento ~/backup-limpieza --defaults-extra-file=~/.my-ascento.cnf
set -euo pipefail

DB="${1:?Falta la base}"
OUT="${2:?Falta la carpeta de destino}"
shift 2
CONN=("$@")
mkdir -p "$OUT"

DUMP=(mysqldump "${CONN[@]}" --single-transaction --no-tablespaces --set-gtid-purged=OFF --skip-dump-date)
NOTICES="type = 'App\\\\Notifications\\\\Marketplace\\\\MarketplaceNotice' AND (dedupe_key LIKE 'mkt:team:svc-%' OR dedupe_key LIKE 'mkt:s%:svc:%' OR dedupe_key LIKE 'mkt:c%:svc:%' OR dedupe_key LIKE 'mkt:sr%')"

# 1. Las 7 tablas completas (estructura y datos). service_catalog_items NO: es una función vigente.
"${DUMP[@]}" "$DB" service_categories service_profiles service_profile_category service_profile_terms \
  service_contact_requests service_contact_messages service_profile_reports > "$OUT/1-tablas-servicios.sql"
# 2. suppliers completa (para recuperar la columna sells_products con sus valores).
"${DUMP[@]}" "$DB" suppliers > "$OUT/2-suppliers.sql"
# 3 y 4. Solo las filas que se borran de notifications y platform_audit_logs (sin estructura).
"${DUMP[@]}" --no-create-info --where="$NOTICES" "$DB" notifications > "$OUT/3-avisos.sql"
"${DUMP[@]}" --no-create-info --where="action LIKE 'marketplace.service%'" "$DB" platform_audit_logs > "$OUT/4-auditoria.sql"

# Conteos de lo respaldado (compararlos con el inventario D, G y H).
mysql "${CONN[@]}" -N "$DB" -e "
  SELECT 'service_profiles', COUNT(*) FROM service_profiles
  UNION ALL SELECT 'service_contact_requests', COUNT(*) FROM service_contact_requests
  UNION ALL SELECT 'service_contact_messages', COUNT(*) FROM service_contact_messages
  UNION ALL SELECT 'service_profile_reports', COUNT(*) FROM service_profile_reports
  UNION ALL SELECT 'suppliers', COUNT(*) FROM suppliers
  UNION ALL SELECT 'avisos', COUNT(*) FROM notifications WHERE $NOTICES
  UNION ALL SELECT 'auditoria', COUNT(*) FROM platform_audit_logs WHERE action LIKE 'marketplace.service%';" > "$OUT/conteos.txt"

( cd "$OUT" && shasum -a 256 ./*.sql conteos.txt > SHA256SUMS )
echo "Copia lista en $OUT:"; cat "$OUT/conteos.txt"
