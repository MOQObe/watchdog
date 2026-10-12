<?php

namespace moqo\watchdog\migrations;

use craft\db\Migration;
use moqo\watchdog\services\Mail;
use moqo\watchdog\services\Pairing;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->dropTableIfExists(Mail::TABLE);
        $this->createTable(Mail::TABLE, [
            'id' => $this->primaryKey(),
            'occurredAt' => $this->integer()->notNull(),
            // Form plugin that was sending the mail ("formie", "freeform"), if any.
            'origin' => $this->string(20),
            // Id under which that plugin's own log reports the same failure, e.g. "formie:412".
            'reportedAs' => $this->string(64),
            'form' => $this->string(),
            'submissionId' => $this->integer(),
            'subject' => $this->string(),
            'recipients' => $this->text(),
            'error' => $this->text(),
        ]);
        $this->createIndex(null, Mail::TABLE, ['occurredAt']);
        $this->createIndex(null, Mail::TABLE, ['reportedAs']);

        self::createPairings($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Pairing::TABLE);
        $this->dropTableIfExists(Mail::TABLE);

        return true;
    }

    /** Shared with the migration that adds this table to sites installed before pairing existed. */
    public static function createPairings(Migration $migration): void
    {
        $migration->dropTableIfExists(Pairing::TABLE);
        $migration->createTable(Pairing::TABLE, [
            'id' => $migration->primaryKey(),
            // SHA-256 of the verifier only the Watchdog server knows.
            'challenge' => $migration->char(64)->notNull(),
            'label' => $migration->string(),
            'keyHash' => $migration->char(64)->notNull(),
            // The key itself, encrypted, until the server has collected it. Null afterwards.
            'pendingKey' => $migration->text(),
            'approvedBy' => $migration->integer(),
            'approvedAt' => $migration->integer()->notNull(),
            'claimedAt' => $migration->integer(),
            'lastUsedAt' => $migration->integer(),
        ]);
        $migration->createIndex(null, Pairing::TABLE, ['challenge'], true);
        $migration->createIndex(null, Pairing::TABLE, ['keyHash']);
    }
}
