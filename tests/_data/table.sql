CREATE TABLE `foobar` (
    `foobar_id` INT(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'foobar, bar',
    `foo_name` VARCHAR(255) DEFAULT NULL,
    `bar_txt` text DEFAULT NULL,
    `sex` tinyint(1) NOT NULL DEFAULT 1,
    `notes` mediumtext COMMENT 'perfect col, with mediumtext',
    `created` datetime NOT NULL,
    PRIMARY KEY (`foobar_id`),
    KEY `name` (`foo_name`)
) ENGINE=InnoDB AUTO_INCREMENT=1234 DEFAULT CHARSET=utf8;
