<?php

namespace moqo\watchdog\migrations;

use craft\db\Migration;
use moqo\watchdog\services\Pairing;

class m261012_000000_pairings extends Migration
{
    public function safeUp(): bool
    {
        Install::createPairings($this);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Pairing::TABLE);

        return true;
    }
}
