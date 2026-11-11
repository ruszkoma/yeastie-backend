<?php

namespace App\Database\Migrations;

use CodeIgniter\Database\Migration;

/**
 * API tables. User data is one table of opaque JSON documents (the app owns their shape);
 * premium has its own tables. All times are UTC `DATETIME(3)`.
 */
class CreateApiTables extends Migration
{
    private const OPTIONS = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

    public function up(): void
    {
        // Per-user sequence. Locking this row serialises one user's writes, so sequence numbers
        // become visible in increasing order and `GET /sync?after=` never skips a change.
        $this->db->query('CREATE TABLE sync_user (
            user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            last_seq BIGINT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (user_id)
        ) ' . self::OPTIONS);

        $this->db->query('CREATE TABLE document (
            user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entity_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
            payload LONGTEXT NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            deleted_at DATETIME(3) NULL,
            seq BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (user_id, entity_type, entity_id),
            UNIQUE KEY document_user_seq (user_id, seq)
        ) ' . self::OPTIONS);

        // Results of applied changes, so a retried request returns the same answer.
        $this->db->query('CREATE TABLE applied_change (
            user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            client_change_id CHAR(36) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entity_type VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            entity_id VARCHAR(64) COLLATE utf8mb4_bin NOT NULL,
            status VARCHAR(16) CHARACTER SET ascii NOT NULL,
            seq BIGINT UNSIGNED NOT NULL,
            created_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id, client_change_id),
            KEY applied_change_created (created_at)
        ) ' . self::OPTIONS);

        // Trial state and the account hash Google stores as obfuscatedExternalAccountId.
        $this->db->query('CREATE TABLE entitlement (
            user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            account_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            trial_started_at DATETIME(3) NULL,
            trial_ends_at DATETIME(3) NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (user_id),
            UNIQUE KEY entitlement_account_hash (account_hash)
        ) ' . self::OPTIONS);

        // One row per store purchase token. The token stays after account deletion (user_id NULL),
        // so it cannot be moved to another account.
        $this->db->query('CREATE TABLE purchase (
            token_hash CHAR(64) CHARACTER SET ascii NOT NULL,
            purchase_token TEXT CHARACTER SET ascii NOT NULL,
            user_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NULL,
            account_hash CHAR(64) CHARACTER SET ascii NULL,
            product_id VARCHAR(64) NOT NULL,
            base_plan_id VARCHAR(64) NULL,
            state VARCHAR(48) CHARACTER SET ascii NOT NULL,
            expires_at DATETIME(3) NULL,
            auto_renewing TINYINT(1) NOT NULL DEFAULT 0,
            linked_token_hash CHAR(64) CHARACTER SET ascii NULL,
            created_at DATETIME(3) NOT NULL,
            updated_at DATETIME(3) NOT NULL,
            PRIMARY KEY (token_hash),
            KEY purchase_user (user_id)
        ) ' . self::OPTIONS);

        $this->db->query('CREATE TABLE rtdn_event (
            message_id VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            received_at DATETIME(3) NOT NULL,
            PRIMARY KEY (message_id),
            KEY rtdn_event_received (received_at)
        ) ' . self::OPTIONS);
    }

    public function down(): void
    {
        foreach (['rtdn_event', 'purchase', 'entitlement', 'applied_change', 'document', 'sync_user'] as $table) {
            $this->db->query('DROP TABLE IF EXISTS ' . $table);
        }
    }
}
