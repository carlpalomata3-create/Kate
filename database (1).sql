-- ============================================================
-- database_update.sql — Run this in phpMyAdmin
-- This adds the email column for student self-registration
-- and fixes passwords.
-- ============================================================

-- 1. Add email column to users (skip if already exists)
ALTER TABLE users ADD COLUMN email VARCHAR(150) NULL AFTER full_name;

-- 2. Fix passwords
UPDATE users SET password = '$2y$12$XuCxKbnYHaOJUFVGcFr.IuKp8Oz95Bp5o6uqbPm/V.I1.5PmFdxjy' WHERE username = 'librarian';
UPDATE users SET password = '$2y$12$TH37DkxTSX6tgHMN3sE9E.e0B/a8TkLdkHp.s4yN2rLVUqOUHbFJi' WHERE username = 'student';

-- 3. Remove admin account
DELETE FROM users WHERE username = 'admin';

-- 4. Update full names
UPDATE users SET full_name = 'Maria Clara' WHERE username = 'librarian';
UPDATE users SET full_name = 'Clark Rufo'  WHERE username = 'student';

-- After running this:
-- librarian login: username=librarian  password=admin123
-- student login:   username=student    password=student123