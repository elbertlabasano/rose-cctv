<?php

return [
    'mysql_dump_binary' => env('BACKUP_MYSQL_DUMP_BINARY', 'mysqldump'),
    'timeout' => 120,
    'temp_directory' => storage_path('app/private/database-backups'),
    'attachments_temp_directory' => storage_path('app/private/attachment-backups'),
    'google_drive_folder_id' => env('BACKUP_GOOGLE_DRIVE_FOLDER_ID'),
];
