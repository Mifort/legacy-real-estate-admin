-- Владелец черновика загрузок. Ключ черновика попадает в публичный путь фото,
-- поэтому право на него фиксируется на сервере: первый загрузивший — владелец,
-- другим пользователям загрузка и ссылка на файлы этого черновика запрещены.
CREATE TABLE `NL_UPLOAD_DRAFT`  (
  `NL_UPLOAD_DRAFT_TOKEN` char(32) NOT NULL,
  `ID_NL_USER` int(11) NOT NULL,
  `NL_UPLOAD_DRAFT_TIME` datetime NOT NULL,
  PRIMARY KEY (`NL_UPLOAD_DRAFT_TOKEN`) USING BTREE,
  INDEX `ID_NL_USER`(`ID_NL_USER`) USING BTREE
) ENGINE = InnoDB;
