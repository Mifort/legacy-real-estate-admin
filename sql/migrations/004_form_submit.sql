-- Защита от повторной отправки формы добавления: ключ отправки -> созданная запись.
-- Строка вставляется в той же транзакции, что и запись; уникальный ключ не даёт
-- параллельному повтору с тем же ключом создать дубликат.
CREATE TABLE `NL_FORM_SUBMIT`  (
  `ID_NL_FORM_SUBMIT` int(11) NOT NULL AUTO_INCREMENT,
  `NL_FORM_SUBMIT_TOKEN` char(32) NOT NULL,
  `NL_FORM_SUBMIT_TABLE` varchar(64) NOT NULL,
  `NL_FORM_SUBMIT_RECORD` int(11) NOT NULL,
  `ID_NL_USER` int(11) NOT NULL,
  `NL_FORM_SUBMIT_TIME` datetime NOT NULL,
  PRIMARY KEY (`ID_NL_FORM_SUBMIT`) USING BTREE,
  UNIQUE INDEX `token`(`NL_FORM_SUBMIT_TOKEN`) USING BTREE
) ENGINE = InnoDB;
