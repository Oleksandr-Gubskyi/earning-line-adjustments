-- Integration tests run against a separate schema so they never touch demo data.
CREATE DATABASE IF NOT EXISTS payroll_test
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_0900_ai_ci;

GRANT ALL PRIVILEGES ON payroll_test.* TO 'payroll'@'%';
FLUSH PRIVILEGES;
