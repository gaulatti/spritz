<?php

require_once __DIR__ . '/../bin/restore-db.php';

function assert_restore_statement_allowed(string $sql): void {
    try {
        assert_supported_restore_statement($sql);
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'Expected supported restore SQL: ' . $exception->getMessage() . PHP_EOL);
        exit(1);
    }
}

function assert_restore_statement_rejected(string $sql): void {
    try {
        assert_supported_restore_statement($sql);
    } catch (RuntimeException $exception) {
        return;
    }

    fwrite(STDERR, "Expected unsupported restore SQL to be rejected.\n");
    exit(1);
}

assert_restore_statement_allowed("INSERT INTO `items` (`body`) VALUES ('CREATE PROCEDURE is ordinary content')");
assert_restore_statement_allowed("CREATE TABLE `events` (`trigger` VARCHAR(32))");
assert_restore_statement_rejected("DELIMITER //\nCREATE PROCEDURE p() BEGIN SELECT 1");
assert_restore_statement_rejected("CREATE DEFINER=`root`@`%` FUNCTION f() RETURNS INT RETURN 1");
assert_restore_statement_rejected("CREATE TRIGGER t BEFORE INSERT ON items FOR EACH ROW SET NEW.id = 1");
assert_restore_statement_rejected("CREATE EVENT cleanup ON SCHEDULE EVERY 1 DAY DO DELETE FROM items");
assert_restore_statement_rejected("/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */");

fwrite(STDOUT, "restore parser guardrail tests passed\n");
