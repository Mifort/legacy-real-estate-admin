-- Задача 1: пароль пользователю admin (AES_ENCRYPT, ключ из php/config.php)
UPDATE `NL_USER` SET `NL_USER_PASSWORD` = AES_ENCRYPT('REDACTED_LOCAL_SECRET', 'REDACTED_LOCAL_SECRET') WHERE `NL_USER_LOGIN` = 'admin';
