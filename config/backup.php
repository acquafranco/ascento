<?php

/*
|--------------------------------------------------------------------------
| Backups de Ascento (recuperación operativa)
|--------------------------------------------------------------------------
|
| Copia restaurable de TODO Ascento: base de datos + archivos privados y
| públicos. No confundir con la exportación de datos de una empresa (Excel
| para el cliente, no restaurable). Ver docs/backups.md.
|
| IMPORTANTE: por defecto se guarda en el mismo servidor (storage/app/backups).
| Eso protege de errores y despliegues fallidos, NO de perder el servidor:
| hay que copiar los archivos afuera (ver docs/backups.md).
|
*/

return [

    // Carpeta en el disco privado "local".
    'path' => 'backups',

    // Cifrado AES-256 del ZIP. Sin contraseña, el backup queda sin cifrar
    // (ascento:check-production lo marca). Guardar la contraseña FUERA del
    // servidor: sin ella no se puede restaurar.
    'password' => env('BACKUP_ARCHIVE_PASSWORD'),

    'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
    'mysql' => env('BACKUP_MYSQL', 'mysql'),

    // Retención: últimos N diarios automáticos, 1 por semana las últimas N
    // semanas, y los manuales por N días. Nunca se borra el último completo.
    'keep_daily' => (int) env('BACKUP_KEEP_DAILY', 7),
    'keep_weekly' => (int) env('BACKUP_KEEP_WEEKLY', 4),
    'keep_manual_days' => (int) env('BACKUP_KEEP_MANUAL_DAYS', 30),

    // Raíz de los archivos (null = storage/app). Carpetas que se respaldan
    // (relativas a la raíz) y las que se excluyen.
    'files_root' => null,
    'include' => ['private', 'public'],
    'exclude' => ['private/exports', 'private/backups', 'private/livewire-tmp', 'backups', 'restore'],

    // Aviso si falla (además de la campanita de los superadmins).
    'notify_email' => env('BACKUP_NOTIFY_EMAIL'),

];
